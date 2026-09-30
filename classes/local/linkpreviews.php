<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_messagingsupercharger\local;

/**
 * Link previews: title, description and image for URLs in messages.
 *
 * Previews are fetched by the server in a background task when a message is SENT,
 * never when someone views it, and are cached by URL. Because this makes the server
 * request URLs that users choose, the feature is off by default and every request goes
 * through {@see self::fetch()}, which refuses anything but http(s) on the standard
 * ports, resolves the host itself and connects only to public addresses (pinning the
 * connection to the address it checked, so a second DNS answer cannot redirect it),
 * follows redirects only after re-checking them, and caps time and size. Core's own
 * cURL blocklist is applied on top. The preview image is downloaded by the server and
 * served from Moodle, so viewers' browsers never contact the linked site.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linkpreviews {
    /** @var int Status: waiting for the fetch task. */
    const STATUS_PENDING = 0;
    /** @var int Status: fetched. */
    const STATUS_OK = 1;
    /** @var int Status: could not be fetched or had nothing to show. */
    const STATUS_FAILED = 2;

    /** @var int Most URLs previewed per message. */
    const MAX_PER_MESSAGE = 3;
    /** @var int Largest HTML page read, in bytes. */
    const MAX_PAGE_BYTES = 512 * 1024;
    /** @var int Largest preview image read, in bytes. */
    const MAX_IMAGE_BYTES = 1024 * 1024;
    /** @var int Total request timeout, seconds. */
    const TIMEOUT = 5;
    /** @var int Redirects followed. */
    const MAX_REDIRECTS = 3;

    /**
     * The http(s) links in message HTML, in order, without duplicates.
     *
     * @param string $html
     * @return string[]
     */
    public static function extract_urls(string $html): array {
        $urls = [];
        if (preg_match_all('~<a\s[^>]*href\s*=\s*"(https?://[^"]+)"~i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (strpos($url, '/pluginfile.php/') !== false || strpos($url, '/user/profile.php') !== false) {
                    continue;
                }
                $urls[$url] = true;
            }
        }
        return array_slice(array_keys($urls), 0, self::MAX_PER_MESSAGE);
    }

    /**
     * Queue fetches for the links in a newly sent message.
     *
     * @param string $html
     */
    public static function queue_for_html(string $html): void {
        global $DB;
        // The same extraction as for_messages() uses when displaying, so the stored URL (and
        // its hash) is the one looked up later.
        foreach (self::extract_urls($html) as $url) {
            if (!self::is_allowed_url($url)) {
                continue;
            }
            $hash = sha1($url);
            if ($DB->record_exists('local_messagingsupercharger_preview', ['urlhash' => $hash])) {
                continue;
            }
            try {
                $DB->insert_record('local_messagingsupercharger_preview', (object)[
                    'urlhash' => $hash,
                    'url' => $url,
                    'status' => self::STATUS_PENDING,
                    'hasimage' => 0,
                    'timefetched' => time(),
                ]);
            } catch (\dml_write_exception $e) {
                continue; // Someone else queued it first.
            }
            $task = new \local_messagingsupercharger\task\fetch_link_preview();
            $task->set_custom_data(['urlhash' => $hash]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Fetch and store the preview for a queued URL.
     *
     * @param string $urlhash
     */
    public static function fetch_and_store(string $urlhash): void {
        global $DB;
        $row = $DB->get_record('local_messagingsupercharger_preview', ['urlhash' => $urlhash]);
        if (!$row || (int)$row->status !== self::STATUS_PENDING) {
            return;
        }
        $row->status = self::STATUS_FAILED;
        $row->timefetched = time();
        if (features::enabled(features::LINKPREVIEWS) && self::is_allowed_url($row->url)) {
            $page = self::fetch($row->url, self::MAX_PAGE_BYTES, ['text/html', 'application/xhtml+xml']);
            if ($page !== null) {
                $meta = self::parse_meta($page['body'], $page['url']);
                if ($meta['title'] !== '' || $meta['description'] !== '') {
                    $row->title = \core_text::substr($meta['title'], 0, 300);
                    $row->description = \core_text::substr($meta['description'], 0, 600);
                    $row->status = self::STATUS_OK;
                    if ($meta['image'] !== '') {
                        $row->hasimage = self::store_image((int)$row->id, $meta['image']) ? 1 : 0;
                    }
                }
            }
        }
        $DB->update_record('local_messagingsupercharger_preview', $row);
    }

    /**
     * Download a preview image into the plugin's file area.
     *
     * @param int $previewid
     * @param string $url
     * @return bool
     */
    protected static function store_image(int $previewid, string $url): bool {
        if (!self::is_allowed_url($url)) {
            return false;
        }
        $types = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        $image = self::fetch($url, self::MAX_IMAGE_BYTES, $types);
        if ($image === null || @getimagesizefromstring($image['body']) === false) {
            return false;
        }
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
            'image/webp' => 'webp'][$image['type']];
        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        $fs->delete_area_files($syscontextid, features::COMPONENT, attachments::AREA_PREVIEW, $previewid);
        $fs->create_file_from_string([
            'contextid' => $syscontextid,
            'component' => features::COMPONENT,
            'filearea' => attachments::AREA_PREVIEW,
            'itemid' => $previewid,
            'filepath' => '/',
            'filename' => 'preview.' . $extension,
        ], $image['body']);
        return true;
    }

    /**
     * Is this URL one the site may fetch at all (scheme, port, allowlist, core blocklist)?
     * Address checks happen at fetch time, after resolution.
     *
     * @param string $url
     * @return bool
     */
    public static function is_allowed_url(string $url): bool {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array((int)$port, [80, 443], true)) {
            return false;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (!self::host_on_allowlist($host)) {
            return false;
        }
        $helper = new \core\files\curl_security_helper();
        return !$helper->url_is_blocked($url);
    }

    /**
     * Check the host against the administrator's allowlist (empty = any public host).
     *
     * @param string $host
     * @return bool
     */
    public static function host_on_allowlist(string $host): bool {
        $list = trim((string)get_config(features::COMPONENT, 'linkpreviewallowlist'));
        if ($list === '') {
            return true;
        }
        foreach (preg_split('~[\s,]+~', \core_text::strtolower($list), -1, PREG_SPLIT_NO_EMPTY) as $domain) {
            $domain = ltrim($domain, '.*');
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is this a public unicast address?
     *
     * @param string $ip
     * @return bool
     */
    public static function is_public_ip(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            $blocked = [
                ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15],
                ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4],
            ];
            foreach ($blocked as [$network, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($network) & $mask)) {
                    return false;
                }
            }
            return true;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        // IPv4-mapped/compatible, NAT64, unique-local, link-local, multicast and documentation ranges.
        $hex = bin2hex($packed);
        foreach (
            ['00000000000000000000ffff', '000000000000000000000000', '0064ff9b', 'fc', 'fd', 'fe8', 'fe9', 'fea',
                'feb', 'ff', '20010db8'] as $prefix
        ) {
            if (str_starts_with($hex, $prefix)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Resolve a host to the addresses it would connect to; all must be public.
     *
     * @param string $host
     * @return string|null The address to pin the connection to, or null to refuse
     */
    public static function resolve_public(string $host): ?string {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::is_public_ip($host) ? $host : null;
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) {
                return null;
            }
        }
        return $ips[0];
    }

    /**
     * Fetch a URL safely.
     *
     * Raw cURL is used rather than core's \curl class because the connection has to be
     * pinned to the checked address (CURLOPT_RESOLVE) and the download aborted at a size
     * cap (write callback); a configured proxy would defeat the address check, so none
     * is used and sites that need one simply get no previews.
     *
     * @param string $url
     * @param int $maxbytes
     * @param string[] $types Acceptable content types
     * @return array|null ['body' => string, 'type' => string, 'url' => final URL], or null
     */
    public static function fetch(string $url, int $maxbytes, array $types): ?array {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!self::is_allowed_url($url)) {
                return null;
            }
            $parts = parse_url($url);
            $host = trim($parts['host'], '[]');
            $scheme = strtolower($parts['scheme']);
            $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $ip = self::resolve_public($host);
            if ($ip === null) {
                return null;
            }

            $body = '';
            $toolarge = false;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (strpos($ip, ':') !== false ? "[$ip]" : $ip)],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_NOPROXY => '*',
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_USERAGENT => 'Moodle link preview (local_messagingsupercharger)',
                CURLOPT_HTTPHEADER => ['Accept: ' . implode(', ', $types)],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_MAXFILESIZE => $maxbytes,
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$toolarge, $maxbytes) {
                    if (strlen($body) + strlen($chunk) > $maxbytes) {
                        $toolarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
            $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error = curl_errno($ch);
            curl_close($ch);

            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                $url = $location;
                continue;
            }
            if ($error || $toolarge || $status !== 200 || !in_array($type, $types, true)) {
                return null;
            }
            return ['body' => $body, 'type' => $type, 'url' => $url];
        }
        return null;
    }

    /**
     * Read the title, description and image from a page's head.
     *
     * @param string $html
     * @param string $baseurl
     * @return array ['title', 'description', 'image']
     */
    public static function parse_meta(string $html, string $baseurl): array {
        $result = ['title' => '', 'description' => '', 'image' => ''];
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $result;
        }
        $meta = [];
        foreach ($doc->getElementsByTagName('meta') as $tag) {
            $key = strtolower($tag->getAttribute('property') ?: $tag->getAttribute('name'));
            if ($key !== '' && !isset($meta[$key])) {
                $meta[$key] = trim($tag->getAttribute('content'));
            }
        }
        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? '';
        if ($title === '') {
            $titles = $doc->getElementsByTagName('title');
            $title = $titles->length ? trim($titles->item(0)->textContent) : '';
        }
        $result['title'] = clean_param(preg_replace('~\s+~u', ' ', $title), PARAM_TEXT);
        $description = $meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? '';
        $result['description'] = clean_param(preg_replace('~\s+~u', ' ', $description), PARAM_TEXT);
        $image = $meta['og:image'] ?? $meta['twitter:image'] ?? '';
        if ($image !== '') {
            $absolute = self::absolute_url($image, $baseurl);
            $result['image'] = $absolute ?? '';
        }
        return $result;
    }

    /**
     * Make a URL absolute against a base.
     *
     * @param string $url
     * @param string $base
     * @return string|null
     */
    protected static function absolute_url(string $url, string $base): ?string {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $parts = parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($url, '//')) {
            return $parts['scheme'] . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        $path = $parts['path'] ?? '/';
        return $origin . substr($path, 0, strrpos($path, '/') + 1) . $url;
    }

    /**
     * May a user see a preview's image? Only if they can see a message that links to its
     * URL: a member of the conversation who has not deleted the message.
     *
     * @param int $previewid
     * @param int $userid
     * @return bool
     */
    public static function can_view_preview(int $previewid, int $userid): bool {
        global $DB;
        $url = $DB->get_field('local_messagingsupercharger_preview', 'url', ['id' => $previewid]);
        if ($url === false || $userid <= 0) {
            return false;
        }
        // The URL can appear in message HTML raw or escaped (&amp;, &#039; or &apos;).
        $needles = array_unique([
            $url,
            htmlspecialchars($url, ENT_QUOTES | ENT_HTML401, 'UTF-8', false),
            htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8', false),
        ]);
        $likes = [];
        $params = [];
        foreach (array_values($needles) as $i => $needle) {
            $likes[] = $DB->sql_like('m.smallmessage', ':url' . $i, true, true);
            $params['url' . $i] = '%' . $DB->sql_like_escape($needle) . '%';
        }
        $like = '(' . implode(' OR ', $likes) . ')';
        $sql = "SELECT 1
                  FROM {messages} m
                  JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid AND mcm.userid = :userid
             LEFT JOIN {message_user_actions} mua
                    ON mua.messageid = m.id AND mua.userid = :userid2 AND mua.action = :deleted
                 WHERE mua.id IS NULL AND $like";
        return $DB->record_exists_sql($sql, $params + [
            'userid' => $userid,
            'userid2' => $userid,
            'deleted' => \core_message\api::MESSAGE_ACTION_DELETED,
        ]);
    }

    /**
     * Previews for the links in a set of messages, for display.
     *
     * @param array $messages messageid => message HTML
     * @return array messageid => list of ['url', 'title', 'description', 'imageurl']
     */
    public static function for_messages(array $messages): array {
        global $DB;
        if (!features::enabled(features::LINKPREVIEWS)) {
            return [];
        }
        $byhash = [];
        foreach ($messages as $messageid => $html) {
            foreach (self::extract_urls((string)$html) as $url) {
                $byhash[sha1($url)][] = $messageid;
            }
        }
        if (!$byhash) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($byhash), SQL_PARAMS_NAMED);
        $params['status'] = self::STATUS_OK;
        $rows = $DB->get_records_select('local_messagingsupercharger_preview', "urlhash $insql AND status = :status", $params);
        $result = [];
        $syscontextid = \context_system::instance()->id;
        // All the preview images in one query.
        $filenames = [];
        $withimage = array_keys(array_filter($rows, fn($row) => !empty($row->hasimage)));
        if ($withimage) {
            [$fsql, $fparams] = $DB->get_in_or_equal($withimage, SQL_PARAMS_NAMED);
            $fparams += ['contextid' => $syscontextid, 'component' => features::COMPONENT,
                'filearea' => attachments::AREA_PREVIEW];
            $filenames = $DB->get_records_select_menu('files', "contextid = :contextid AND component = :component
                AND filearea = :filearea AND itemid $fsql AND filename <> '.'", $fparams, '', 'itemid, filename');
        }
        foreach ($rows as $row) {
            $imageurl = '';
            if (isset($filenames[$row->id])) {
                $imageurl = \moodle_url::make_pluginfile_url(
                    $syscontextid,
                    features::COMPONENT,
                    attachments::AREA_PREVIEW,
                    $row->id,
                    '/',
                    $filenames[$row->id]
                )->out(false);
            }
            foreach ($byhash[$row->urlhash] as $messageid) {
                $result[$messageid][] = [
                    'url' => $row->url,
                    'title' => (string)$row->title,
                    'description' => (string)$row->description,
                    'imageurl' => $imageurl,
                ];
            }
        }
        return $result;
    }
}
