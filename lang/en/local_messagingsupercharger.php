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

/**
 * English strings for local_messagingsupercharger.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addreaction'] = 'Add reaction';
$string['attachfile'] = 'Attach files';
$string['attachmentcontentmismatch'] = 'The contents of "{$a}" do not match its file type, so it cannot be attached.';
$string['attachmentexecutable'] = '"{$a}" is a program or script, which cannot be attached.';
$string['attachmentonly'] = 'Attachment: {$a}';
$string['attachmentretention'] = 'Keep attachments for';
$string['attachmentretention_desc'] = 'Delete files attached to messages this long after the message was sent. The message itself stays, with a note that its attachments have expired. 0 keeps attachments as long as the message exists.';
$string['attachments'] = 'Attachments';
$string['attachmentsexpired'] = 'Attachments to this message have expired.';
$string['attachmentsheading'] = 'Attachments';
$string['attachmenttoolarge'] = '"{$a->name}" is larger than the maximum of {$a->max}.';
$string['attachmenttoolargejs'] = 'Too large: {$a}';
$string['attachmenttypejs'] = 'This type of file cannot be attached: {$a}';
$string['attachmenttypenotallowed'] = 'This type of file cannot be attached: {$a}';
$string['attachmenttypes'] = 'Allowed attachment types';
$string['attachmenttypes_desc'] = 'The types of file people may attach to messages.';
$string['cancelled'] = 'Cancelled';
$string['cancelscheduled'] = 'Cancel';
$string['cannotsend'] = 'You cannot send messages to this conversation.';
$string['confirmcancelscheduled'] = 'Cancel this scheduled message? It will not be sent.';
$string['confirmdeleteforall'] = 'Delete this message for everyone in the conversation? This cannot be undone.';
$string['deleteforeveryone'] = 'Delete for everyone';
$string['dropfiles'] = 'Drop files here to attach them';
$string['edited'] = 'Edited {$a}';
$string['editingheading'] = 'Editing and email';
$string['editmessage'] = 'Edit';
$string['editnoemail'] = 'Editing changes the message in Moodle only. Nobody is sent another email or notification.';
$string['editscheduled'] = 'Edit';
$string['editwindow'] = 'Edit window';
$string['editwindow_desc'] = 'How long after sending people may edit, or delete for everyone, their own messages. 0 means no limit.';
$string['editwindowpassed'] = 'This message can no longer be changed.';
$string['emaildelay'] = 'Email delay';
$string['emaildelay_desc'] = 'How long to hold back the email for a message in a one-to-one conversation. The email is sent once, when the delay is over, with the text as it is then, so a typo corrected quickly never reaches the inbox. Later edits never send another email. 0 lets Moodle send the email straight away. Group conversations always use Moodle\'s own daily digest.';
$string['emailskipifread'] = 'Skip email if read';
$string['emailskipifread_desc'] = 'Do not send the held email if the recipient has read the message in Moodle before the delay is over.';
$string['emptymessage'] = 'The message is empty.';
$string['enableattachments'] = 'Attachments';
$string['enableattachments_desc'] = 'Attach files to messages, including dragging, dropping and pasting images into the message panel.';
$string['enableediting'] = 'Edit and delete';
$string['enableediting_desc'] = 'Edit your own messages, and delete them for everyone, within the edit window. Edited messages are marked as edited.';
$string['enablelinkpreviews'] = 'Link previews';
$string['enablelinkpreviews_desc'] = 'Show a title, description and image for links in messages. The server fetches the linked pages, so this is off by default; see the link preview settings below.';
$string['enablementions'] = 'Mentions';
$string['enablementions_desc'] = '@-mention members of group conversations. Mentioned people are notified through their own notification type, so they can mute a busy conversation and still hear when they are mentioned.';
$string['enablepinning'] = 'Pinned messages';
$string['enablepinning_desc'] = 'Pin messages to the top of a conversation. In group conversations only people with the "Pin messages" capability can pin.';
$string['enablereactions'] = 'Reactions';
$string['enablereactions_desc'] = 'React to individual messages.';
$string['enableresizabledrawer'] = 'Resizable drawer';
$string['enableresizabledrawer_desc'] = 'A handle on the edge of the message drawer to make it wider or narrower. Each person\'s width is remembered in their browser.';
$string['enablerichtext'] = 'Rich text editor';
$string['enablerichtext_desc'] = 'Write formatted messages, with attachments, in a full editor.';
$string['enablescheduling'] = 'Scheduled send';
$string['enablescheduling_desc'] = 'Write a message now and have it sent later. Whether it can still be sent is checked when it is sent.';
$string['enablesearch'] = 'Message search';
$string['enablesearch_desc'] = 'Search the text of your messages in all your conversations, including group conversations.';
$string['enableseenby'] = 'Seen by';
$string['enableseenby_desc'] = 'Show who has read the latest message in a group conversation. Each person can switch this off for themselves.';
$string['failed'] = 'Not sent';
$string['failedcannotsend'] = 'You could no longer send messages to this conversation.';
$string['faileddisabled'] = 'Messaging or scheduled send was switched off.';
$string['failedinterrupted'] = 'Sending was interrupted. Check whether the message arrived before sending it again.';
$string['failednoconversation'] = 'The conversation no longer exists.';
$string['failednotmember'] = 'You are no longer in this conversation.';
$string['featuredisabled'] = 'This feature is turned off.';
$string['featuresheading'] = 'Features';
$string['featuresheading_desc'] = 'Each feature can be turned on or off. The capabilities of this plugin control who may use them.';
$string['history'] = 'Earlier versions';
$string['imagefit'] = 'Fit to window';
$string['imageoriginal'] = 'Open original';
$string['imageviewer'] = 'Image';
$string['invalidreaction'] = 'Unknown reaction.';
$string['jumptomessage'] = 'Go to message';
$string['linkpreview'] = 'Link preview';
$string['linkpreviewallowlist'] = 'Allowed sites';
$string['linkpreviewallowlist_desc'] = 'If not empty, only links to these domains (one per line; subdomains included) get a preview.';
$string['linkpreviewsheading'] = 'Link previews';
$string['linkpreviewsheading_desc'] = 'Previews are fetched by the server when a message is sent, never when someone reads it. Only public web addresses on the standard ports are fetched: private and local network addresses are refused, and each request is limited in time and size. Moodle\'s own blocked hosts setting also applies.';
$string['loadmore'] = 'Show more';
$string['maxattachments'] = 'Maximum attachments';
$string['maxattachments_desc'] = 'The most files that can be attached to one message.';
$string['maxattachmentsize'] = 'Maximum attachment size';
$string['maxattachmentsize_desc'] = 'The largest file that can be attached. It can never exceed the site\'s own upload limit.';
$string['mentionbody'] = '{$a->name} mentioned you in {$a->conversation}:

{$a->text}';
$string['mentionlist'] = 'People you can mention';
$string['mentionsmall'] = '{$a->name} mentioned you: {$a->text}';
$string['mentionsubject'] = '{$a->name} mentioned you in {$a->conversation}';
$string['messageactions'] = 'Message actions';
$string['messagenotfound'] = 'Message not found.';
$string['messageprovider:mention'] = 'You are mentioned in a group conversation';
$string['messagetoolong'] = 'The message is too long.';
$string['messagingsupercharger:deleteownmessageforall'] = 'Delete own messages for everyone';
$string['messagingsupercharger:editownmessage'] = 'Edit own messages';
$string['messagingsupercharger:mention'] = 'Mention people in group conversations';
$string['messagingsupercharger:pinmessage'] = 'Pin messages in group conversations';
$string['messagingsupercharger:react'] = 'React to messages';
$string['messagingsupercharger:schedulesend'] = 'Schedule messages';
$string['messagingsupercharger:sendattachments'] = 'Attach files to messages';
$string['messagingsupercharger:userichtext'] = 'Use the rich text editor for messages';
$string['nomentions'] = 'No matching people';
$string['nopermissiontopin'] = 'You cannot pin messages in this conversation.';
$string['noresults'] = 'No messages found';
$string['notamember'] = 'You are not in this conversation.';
$string['notyourmessage'] = 'You can only change your own messages.';
$string['pinmessage'] = 'Pin';
$string['pinned'] = 'Pinned';
$string['pinnedcount'] = 'Pinned ({$a})';
$string['pluginname'] = 'Messaging Supercharger';
$string['pollingheading'] = 'Refreshing';
$string['pollinterval'] = 'Refresh interval';
$string['pollinterval_desc'] = 'Seconds between refreshes of reactions, edits, pins and "seen by" in an open conversation (at least 5).';
$string['privacy:heldemails'] = 'Emails waiting to be sent';
$string['privacy:mentions'] = 'Mentions';
$string['privacy:messages'] = 'Edited messages';
$string['privacy:metadata:core_files'] = 'Files attached to messages, and images in rich-text messages.';
$string['privacy:metadata:core_message'] = 'Messages are sent, stored and deleted by core messaging; this plugin adds to them.';
$string['privacy:metadata:linkpreview'] = 'When link previews are on, the server requests pages linked in messages from the linked site.';
$string['privacy:metadata:linkpreview:url'] = 'The address of the linked page.';
$string['privacy:metadata:local_messagingsupercharger_attach'] = 'Sets of files attached to messages.';
$string['privacy:metadata:local_messagingsupercharger_attach:conversationid'] = 'The conversation the files were sent to.';
$string['privacy:metadata:local_messagingsupercharger_attach:timecreated'] = 'When the files were attached.';
$string['privacy:metadata:local_messagingsupercharger_attach:userid'] = 'The person who attached the files.';
$string['privacy:metadata:local_messagingsupercharger_emailq'] = 'Message emails held back for a short time before being sent.';
$string['privacy:metadata:local_messagingsupercharger_emailq:messageid'] = 'The message.';
$string['privacy:metadata:local_messagingsupercharger_emailq:subject'] = 'The subject of the email, in the recipient\'s language.';
$string['privacy:metadata:local_messagingsupercharger_emailq:timedue'] = 'When the email is due to be sent.';
$string['privacy:metadata:local_messagingsupercharger_emailq:useridfrom'] = 'The sender.';
$string['privacy:metadata:local_messagingsupercharger_emailq:useridto'] = 'The recipient.';
$string['privacy:metadata:local_messagingsupercharger_mention'] = 'People mentioned in messages.';
$string['privacy:metadata:local_messagingsupercharger_mention:messageid'] = 'The message.';
$string['privacy:metadata:local_messagingsupercharger_mention:timecreated'] = 'When the mention was made.';
$string['privacy:metadata:local_messagingsupercharger_mention:userid'] = 'The person mentioned.';
$string['privacy:metadata:local_messagingsupercharger_meta'] = 'The text of messages sent with this plugin, as the author wrote it, and when it was last edited.';
$string['privacy:metadata:local_messagingsupercharger_meta:body'] = 'The text as the author wrote it.';
$string['privacy:metadata:local_messagingsupercharger_meta:messageid'] = 'The message.';
$string['privacy:metadata:local_messagingsupercharger_meta:timeedited'] = 'When the message was last edited.';
$string['privacy:metadata:local_messagingsupercharger_meta:userid'] = 'The author.';
$string['privacy:metadata:local_messagingsupercharger_pin'] = 'Pinned messages.';
$string['privacy:metadata:local_messagingsupercharger_pin:messageid'] = 'The pinned message.';
$string['privacy:metadata:local_messagingsupercharger_pin:timecreated'] = 'When it was pinned.';
$string['privacy:metadata:local_messagingsupercharger_pin:userid'] = 'The person who pinned it.';
$string['privacy:metadata:local_messagingsupercharger_reaction'] = 'Reactions to messages.';
$string['privacy:metadata:local_messagingsupercharger_reaction:messageid'] = 'The message.';
$string['privacy:metadata:local_messagingsupercharger_reaction:reaction'] = 'The reaction.';
$string['privacy:metadata:local_messagingsupercharger_reaction:timecreated'] = 'When the reaction was added.';
$string['privacy:metadata:local_messagingsupercharger_reaction:userid'] = 'The person who reacted.';
$string['privacy:metadata:local_messagingsupercharger_revision'] = 'Earlier versions of edited messages.';
$string['privacy:metadata:local_messagingsupercharger_revision:body'] = 'The text before the edit.';
$string['privacy:metadata:local_messagingsupercharger_revision:messageid'] = 'The message.';
$string['privacy:metadata:local_messagingsupercharger_revision:timecreated'] = 'When the edit was made.';
$string['privacy:metadata:local_messagingsupercharger_revision:userid'] = 'The author.';
$string['privacy:metadata:local_messagingsupercharger_sched'] = 'Messages waiting to be sent at a chosen time.';
$string['privacy:metadata:local_messagingsupercharger_sched:body'] = 'The text of the message.';
$string['privacy:metadata:local_messagingsupercharger_sched:conversationid'] = 'The conversation it will be sent to.';
$string['privacy:metadata:local_messagingsupercharger_sched:mentions'] = 'People mentioned in the message.';
$string['privacy:metadata:local_messagingsupercharger_sched:status'] = 'Whether the message could not be sent.';
$string['privacy:metadata:local_messagingsupercharger_sched:timesend'] = 'When it will be sent.';
$string['privacy:metadata:local_messagingsupercharger_sched:userid'] = 'The sender.';
$string['privacy:metadata:preference:showseenby'] = 'Whether the person shows, and sees, who has read messages in group conversations.';
$string['privacy:pins'] = 'Pinned messages';
$string['privacy:reactions'] = 'Reactions';
$string['privacy:scheduled'] = 'Scheduled messages';
$string['quotaexceeded'] = 'You have reached the limit of {$a} for files attached to messages. Delete some messages with attachments, or ask your administrator.';
$string['reactedwith'] = 'Reacted:';
$string['reaction_heart'] = 'Heart';
$string['reaction_laugh'] = 'Laugh';
$string['reaction_party'] = 'Celebrate';
$string['reaction_sad'] = 'Sad';
$string['reaction_thumbsup'] = 'Thumbs up';
$string['reaction_wow'] = 'Surprised';
$string['removeattachment'] = 'Remove {$a}';
$string['resizedrawer'] = 'Resize the message drawer (drag, or use the arrow keys; double-click to reset)';
$string['richeditor'] = 'Rich text editor';
$string['schedulebeingsent'] = 'This message is being sent right now and can no longer be changed.';
$string['scheduledcount'] = 'Scheduled ({$a})';
$string['schedulednotfound'] = 'Scheduled message not found.';
$string['scheduleinpast'] = 'Choose a time in the future.';
$string['schedulenote'] = 'The message is sent at the chosen time if you can still send to this conversation then.';
$string['schedulesaved'] = 'Message scheduled';
$string['schedulesend'] = 'Schedule message';
$string['scheduletoofar'] = 'Messages can be scheduled up to a year ahead.';
$string['searchmessages'] = 'Search messages';
$string['searchplaceholder'] = 'Search your messages';
$string['seenby'] = 'Seen by {$a}';
$string['seenbyheading'] = 'Seen by';
$string['send'] = 'Send';
$string['sendat'] = 'Send at';
$string['showhistory'] = 'Earlier versions';
$string['showseenby'] = 'Show who has seen messages in group conversations';
$string['showseenby_desc'] = 'When this is off, others do not see when you have read their messages, and you do not see who has read yours.';
$string['taskcleanup'] = 'Messaging Supercharger housekeeping';
$string['toomanyattachments'] = 'At most {$a} files can be attached to a message.';
$string['toomanyattachmentsjs'] = 'At most {$a} files can be attached to a message.';
$string['toomanypins'] = 'At most {$a} messages can be pinned in a conversation.';
$string['toomanyscheduled'] = 'You can have at most {$a} scheduled messages.';
$string['unknownuser'] = 'Unknown user';
$string['unpinmessage'] = 'Unpin';
$string['uploadfailed'] = 'The file could not be uploaded.';
$string['uploading'] = 'Uploading';
$string['userquota'] = 'Attachment storage per user';
$string['userquota_desc'] = 'The most each person can have stored in files attached to messages (their own sent and scheduled messages). Every upload and send is checked against it.';
