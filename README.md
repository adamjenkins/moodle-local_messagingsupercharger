# Messaging Supercharger (local_messagingsupercharger)

Adds the things Moodle's messaging lacks to the message drawer and the messages page,
without changing core:

- **Attachments.** Attach files with the paper-clip button, by dragging and dropping
  anywhere on the conversation, or by pasting an image. Uploads start at once, show
  progress, and report size and type limits clearly. Attached images are shown as wide as
  the panel allows (never larger than their real size); clicking one opens a full-size
  viewer.
- **Rich text.** Write a formatted message, with attachments and embedded images, in the
  full editor. What you had typed in the drawer is carried over.
- **Mentions.** Type `@` in a group conversation to pick a member (keyboard or mouse). The
  mentioned person gets their own notification type ("You are mentioned in a group
  conversation"), so they can mute a busy group and still hear when they are mentioned.
- **Reactions** on any message, with who reacted on hover and to screen readers.
- **Edit and delete your own messages** within a configurable window. Edited messages
  show "Edited" with the time, and anyone in the conversation can see earlier versions.
  "Delete for everyone" uses Moodle's own delete-for-all.
- **Pinned messages** in a collapsible strip at the top of the conversation.
- **Link previews** (off by default; see Security).
- **Seen by**: who has read the latest message in a group conversation. Each person can
  switch it off in the messaging settings panel; then they neither show nor see it.
- **Scheduled send**: write now, send later. Pending messages appear in the strip at the
  top of the conversation, where they can be changed or cancelled.
- **Message search** across all your conversations, group conversations included.
- **Resizable drawer**: drag the handle on the drawer's edge (or focus it and use the
  arrow keys) to make the drawer wider; double-click to reset.

## Email: sent once, a little later

For one-to-one conversations the message email is held back for a short delay (two
minutes by default) and then sent once, with the text as it stands at that moment, so a
typo fixed quickly never reaches the inbox. Later edits never send another email. The
email is skipped if the recipient has already read the message in Moodle (configurable),
has muted the conversation, or the message was deleted. Group conversations keep Moodle's
own daily email digest, which also sends the text as it stands.

How: Moodle has no supported way to delay one email, so the plugin uses the
`pre_processor_message_send` callback to give the email processor, and only the email
processor, a copy of the recipient that it skips; a background task then sends the email.
If anything about that fails, Moodle sends the email immediately as usual (still exactly
once). The PHPUnit suite fails if a future Moodle release stops honouring this.

## Requirements

- Moodle 5.2 or 5.3 (tested on 5.2.2+ and 5.3rc1), PHP 8.3 or later.
- Messaging enabled on the site. The emoji picker, if wanted, is core's
  (`allowemojipicker`).

## Installation

Copy the plugin to `local/messagingsupercharger` (under `public/` on Moodle 5.1 and later)
and visit Site administration > Notifications.

## Settings

Site administration > Plugins > Local plugins > Messaging Supercharger:

- Turn each feature on or off.
- Maximum attachment size (never above the site limit), maximum number of attachments,
  allowed file types.
- Edit window (default 15 minutes; 0 = no limit), email delay (default 2 minutes; 0 = let
  Moodle send immediately), and whether to skip the email if the message was read.
- Link previews: an optional list of allowed domains.
- How often an open conversation refreshes reactions, edits, pins and "seen by".

## Capabilities

All are checked in the conversation's context: the course for a course-group
conversation, the system otherwise, so they can be overridden per course.

| Capability | Default |
|---|---|
| `local/messagingsupercharger:sendattachments` | Authenticated user |
| `local/messagingsupercharger:userichtext` | Authenticated user |
| `local/messagingsupercharger:mention` | Authenticated user |
| `local/messagingsupercharger:react` | Authenticated user |
| `local/messagingsupercharger:editownmessage` | Authenticated user |
| `local/messagingsupercharger:deleteownmessageforall` | Authenticated user |
| `local/messagingsupercharger:pinmessage` | Teacher, non-editing teacher, manager |
| `local/messagingsupercharger:schedulesend` | Authenticated user |

Pinning: in a one-to-one conversation either person may pin; in a group conversation only
people with `pinmessage` in the course may, because a pin is shown to the whole group.

`userichtext` controls the rich text editor. It is not a content filter: Moodle's own
messaging web services also accept formatted text, and every message is cleaned by
Moodle's HTML cleaning when it is shown, whichever way it was written.

All of Moodle's own messaging rules still apply: blocking, "contacts only" privacy,
messaging disabled, conversation membership and disabled group conversations. Scheduled
messages are checked again when they are sent.

## Security and privacy

- Attachments are stored by the plugin in the system context and served only to members
  of the conversation the message belongs to (and to the uploader). Anything that is not
  an image is always sent as a download.
- Message text is cleaned by Moodle's own formatting, as for any message.
- Link previews make the server fetch pages that users link to, so they are off by
  default. When on, previews are fetched once, when a message is sent (never when someone
  reads it), only over http(s) on ports 80/443, only from public addresses (private,
  loopback, link-local and similar ranges are refused after DNS resolution, and the
  connection is pinned to the checked address), with redirects re-checked, a 5 second
  timeout and a 512 KB size cap. Moodle's blocked-hosts setting also applies, and an
  allowlist can restrict previews to chosen sites. Preview images are downloaded by the
  server, so readers' browsers never contact the linked site. A proxy is never used, so
  on sites that need one there are no previews.
- The Privacy API provider covers every table and the file area (export and delete, per
  user and per context). Deleting a person's data deletes the files they attached, also in
  group conversations whose messages Moodle keeps in the course; those messages then list
  the attachments without the files. Pins they made are kept for the conversation without
  their name.
- Link previews are kept once fetched (one per address), because they are only ever
  fetched when a message is sent. Preview images are shown only to people who can see a
  message containing the link.

## The Moodle app and other clients

Attachments, mentions and formatting are part of the message's text, so they appear in
the Moodle app and in email. Reactions, pins, "seen by", the "edited" marker and edits
made after a message was loaded appear in the web interface only. Messages written in the
app are ordinary messages; their email is still held and sent once.

## Maintenance after a Moodle upgrade

Everything the plugin assumes about Moodle's messaging page is in two places:
`amd/src/selectors.js` (the DOM) and `amd/src/send_interceptor.js` (the send web service
names). Check those two files against the core_message templates and JavaScript.

## Licence

GNU GPL v3 or later.
