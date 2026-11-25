<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_BEYONDBOT_INTRO' => 'Beyond Bot is a forum member that answers when someone writes <strong>@Beyond Bot</strong> in one of the forums below. Answers are written by Claude from the OXT-Beyond and xTalk Suite docs, in the background, by the board\'s cron. Every limit is checked before anything is sent to Claude.',

	'ACP_BEYONDBOT_STATUS' => 'Status',
	'ACP_BEYONDBOT_STATUS_SPENT' => 'Spent this month',
	'ACP_BEYONDBOT_STATUS_PENDING' => 'Questions waiting',
	'ACP_BEYONDBOT_STATUS_DOCS' => 'Docs index',
	'ACP_BEYONDBOT_STATUS_DOCS_NONE' => 'Not loaded yet. It loads on the next cron runs after the bundle URL is reachable.',
	'ACP_BEYONDBOT_STATUS_DOCS_LOADED' => '%1$d pieces, built %2$s, OXT-Beyond %3$s',
	'ACP_BEYONDBOT_STATUS_LAST_RUN' => 'Queue last worked',
	'ACP_BEYONDBOT_STATUS_NEVER' => 'never',
	'ACP_BEYONDBOT_STATUS_ERROR' => 'Last API error (the bot is paused until it is fixed)',
	'ACP_BEYONDBOT_CLEAR_ERROR' => 'Clear the error and try again',
	'ACP_BEYONDBOT_UPDATE_DOCS' => 'Update the docs now',
	'ACP_BEYONDBOT_DOCS_QUEUED' => 'The docs index will update on the next cron runs.',
	'ACP_BEYONDBOT_ERROR_CLEARED' => 'Error cleared. Waiting questions will be tried again on the next cron run.',
	'ACP_BEYONDBOT_BOT_MEMBER' => 'Bot member',

	'ACP_BEYONDBOT_GENERAL' => 'Switch and key',
	'ACP_BEYONDBOT_ENABLED' => 'Beyond Bot is on',
	'ACP_BEYONDBOT_ENABLED_EXPLAIN' => 'The kill switch. When off, mentions are ignored and nothing is sent to Claude.',
	'ACP_BEYONDBOT_API_KEY' => 'Claude API key',
	'ACP_BEYONDBOT_API_KEY_EXPLAIN' => 'From the Claude Console. Use a key from its own workspace with a spend limit set there too. The key is never shown again; leave the box empty to keep the current one.',
	'ACP_BEYONDBOT_KEY_SAVED' => 'A key is saved, ending %s.',
	'ACP_BEYONDBOT_KEY_NONE' => 'No key saved.',
	'ACP_BEYONDBOT_REMOVE_KEY' => 'Remove the saved key',

	'ACP_BEYONDBOT_WHERE' => 'Where and who',
	'ACP_BEYONDBOT_FORUMS' => 'Forums it answers in',
	'ACP_BEYONDBOT_FORUMS_EXPLAIN' => 'Only these forums. Subforums are not included unless chosen too. Tick each one it should answer in.',
	'ACP_BEYONDBOT_GROUPS' => 'Who may ask',
	'ACP_BEYONDBOT_GROUPS_EXPLAIN' => 'Members of any ticked group. Tick none to let any member ask.',
	'ACP_BEYONDBOT_MIN_POSTS' => 'Posts a member needs first',
	'ACP_BEYONDBOT_MIN_POSTS_EXPLAIN' => 'Keeps brand-new and throwaway accounts from using the bot.',
	'ACP_BEYONDBOT_BLOCKED_TOPICS' => 'Topics it never answers in',
	'ACP_BEYONDBOT_BLOCKED_TOPICS_EXPLAIN' => 'Topic numbers (the t= in a topic\'s address), separated by commas.',

	'ACP_BEYONDBOT_LIMITS' => 'Limits',
	'ACP_BEYONDBOT_USER_DAILY' => 'Answers per member per day',
	'ACP_BEYONDBOT_USER_DAILY_EXPLAIN' => 'Counted over the last 24 hours. When a member reaches it, the bot posts one short note (no cost) and answers again the next day.',
	'ACP_BEYONDBOT_TOPIC_MAX' => 'Answers per topic',
	'ACP_BEYONDBOT_BOARD_DAILY' => 'Answers per day, whole board',
	'ACP_BEYONDBOT_MONTH_CAP' => 'Monthly spend cap (US dollars)',
	'ACP_BEYONDBOT_MONTH_CAP_EXPLAIN' => 'Counted from the real token use of every call this calendar month (UTC). At the cap the bot stops until the 1st.',

	'ACP_BEYONDBOT_MODEL_HEAD' => 'Model',
	'ACP_BEYONDBOT_MODEL' => 'Model',
	'ACP_BEYONDBOT_MODEL_EXPLAIN' => 'Sonnet 5.5 suits most questions. Opus 5.5 is stronger on hard script problems and costs about twice as much.',
	'ACP_BEYONDBOT_CUSTOM_MODEL' => 'Other model id',
	'ACP_BEYONDBOT_CUSTOM_MODEL_EXPLAIN' => 'Leave empty to use the model above. A model id here (for example a newer one) is used instead.',
	'ACP_BEYONDBOT_CUSTOM_PRICES' => 'Its prices per million tokens (input, output)',
	'ACP_BEYONDBOT_CUSTOM_PRICES_EXPLAIN' => 'Used for the spend cap. Left at 0, it is counted at the dearest known model\'s prices, so the cap stops early rather than late.',
	'ACP_BEYONDBOT_EFFORT' => 'Effort',
	'ACP_BEYONDBOT_EFFORT_EXPLAIN' => 'How hard the model thinks before answering. Higher is slower and costs more.',
	'ACP_BEYONDBOT_EFFORT_LOW' => 'Low',
	'ACP_BEYONDBOT_EFFORT_MEDIUM' => 'Medium (recommended)',
	'ACP_BEYONDBOT_EFFORT_HIGH' => 'High',
	'ACP_BEYONDBOT_EFFORT_XHIGH' => 'Extra high',
	'ACP_BEYONDBOT_EFFORT_MAX' => 'Max',
	'ACP_BEYONDBOT_MAX_TOKENS' => 'Most tokens per call',
	'ACP_BEYONDBOT_MAX_TOKENS_EXPLAIN' => 'Thinking and the answer together. 8000 is plenty.',
	'ACP_BEYONDBOT_MAX_CHARS' => 'Longest answer (characters)',

	'ACP_BEYONDBOT_CONTENT' => 'Answers and docs',
	'ACP_BEYONDBOT_DOMAINS' => 'Sites answers may link to',
	'ACP_BEYONDBOT_DOMAINS_EXPLAIN' => 'Links to any other site are posted as plain text. Subdomains are included.',
	'ACP_BEYONDBOT_EXTRA' => 'Extra instructions',
	'ACP_BEYONDBOT_EXTRA_EXPLAIN' => 'Added to the bot\'s standing instructions, for example "Mention the Raspberry Pi section for Pi questions." Members never see this.',
	'ACP_BEYONDBOT_KB_URL' => 'Docs bundle (manifest URL)',
	'ACP_BEYONDBOT_KB_URL_EXPLAIN' => 'Where the weekly docs index comes from. Leave the default unless you build your own.',
	'ACP_BEYONDBOT_API_BASE' => 'API address',
	'ACP_BEYONDBOT_API_BASE_EXPLAIN' => 'Leave empty. Only for testing against a stand-in server.',

	'ACP_BEYONDBOT_BAD_MODEL' => 'The model id may only use lower-case letters, digits, dots, dashes and underscores.',
	'ACP_BEYONDBOT_BAD_KB_URL' => 'The docs bundle URL must be an https address ending in /manifest.json.',
	'ACP_BEYONDBOT_BAD_API_BASE' => 'The API address must start with https:// or http://.',
	'ACP_BEYONDBOT_BAD_KEY' => 'That does not look like an API key. Nothing was saved.',

	'ACP_BEYONDBOT_LOG_INTRO' => 'Everything the bot did, newest first: %1$d entries. Spent this month: %2$s.',
	'ACP_BEYONDBOT_LOG_TIME' => 'When',
	'ACP_BEYONDBOT_LOG_ASKER' => 'Asked by',
	'ACP_BEYONDBOT_LOG_OUTCOME' => 'What happened',
	'ACP_BEYONDBOT_LOG_COST' => 'Model, tokens, cost',
	'ACP_BEYONDBOT_LOG_QUERIES' => 'Docs it looked up',
	'ACP_BEYONDBOT_LOG_QUESTION' => 'question',
	'ACP_BEYONDBOT_LOG_REPLY' => 'reply',
	'ACP_BEYONDBOT_LOG_EMPTY' => 'Nothing yet.',

	'ACP_BEYONDBOT_REPORTS_HEAD' => 'Bug reports',
	'ACP_BEYONDBOT_REPORTS_ENABLED' => 'Save bug reports',
	'ACP_BEYONDBOT_REPORTS_ENABLED_EXPLAIN' => 'When a member tells the bot about a bug in OXT-Beyond or an xTalk Suite extension, it saves a short report to the Bug reports page as well as answering.',
	'ACP_BEYONDBOT_REPORT_FORUMS' => 'Also read every new topic in',
	'ACP_BEYONDBOT_REPORT_FORUMS_EXPLAIN' => 'Optional. Each new topic in a ticked forum is read once by the model below and saved as a report if it describes a bug, even when nobody tags the bot. Nothing is posted. Tick none to save reports only when the bot is asked.',
	'ACP_BEYONDBOT_REPORT_MODEL' => 'Model for reading new topics',
	'ACP_BEYONDBOT_REPORT_MODEL_EXPLAIN' => 'Haiku 5.5 is plenty for this and costs about a tenth of a cent a topic. It counts towards the monthly spend cap.',
	'ACP_BEYONDBOT_REPORTS_DAILY' => 'New reports per day, whole board',
	'ACP_BEYONDBOT_REPORT_REPO' => 'GitHub repository for issues',
	'ACP_BEYONDBOT_REPORT_REPO_EXPLAIN' => 'Written owner/name. The Create GitHub issue button opens this repository\'s new-issue form with the report filled in; you submit it yourself. Leave empty to hide the button.',
	'ACP_BEYONDBOT_BAD_REPO' => 'The GitHub repository must be written owner/name, for example SethMorrowSoftware/OpenXTalk-Beyond.',

	'ACP_BEYONDBOT_REPORTS_INTRO' => 'Bugs members described to Beyond Bot, newest first. Each was summarised by Claude from the member\'s post: check it against the post before acting on it.',
	'ACP_BEYONDBOT_REPORTS_OFF' => 'Saving bug reports is switched off in Settings. The reports below stay until you delete them.',
	'ACP_BEYONDBOT_REPORTS_EMPTY' => 'No reports here.',
	'ACP_BEYONDBOT_REPORT_ALL' => 'All',
	'ACP_BEYONDBOT_REPORT_FROM' => 'From',
	'ACP_BEYONDBOT_REPORT_UPDATED' => 'updated %s',
	'ACP_BEYONDBOT_REPORT_POST' => 'the post',
	'ACP_BEYONDBOT_REPORT_DUP' => 'Possibly the same bug as report #%d',
	'ACP_BEYONDBOT_REPORT_SOURCE_MENTION' => 'told to the bot',
	'ACP_BEYONDBOT_REPORT_SOURCE_FORUM' => 'read from a new topic',
	'ACP_BEYONDBOT_REPORT_WHERE' => 'Part, platform, version',
	'ACP_BEYONDBOT_REPORT_SUMMARY' => 'Summary',
	'ACP_BEYONDBOT_REPORT_STEPS' => 'Steps to reproduce',
	'ACP_BEYONDBOT_REPORT_EXPECTED' => 'Expected',
	'ACP_BEYONDBOT_REPORT_ACTUAL' => 'What happened',
	'ACP_BEYONDBOT_REPORT_STATUS' => 'Status',
	'ACP_BEYONDBOT_REPORT_NOTE' => 'Your note',
	'ACP_BEYONDBOT_REPORT_NOTE_EXPLAIN' => 'For admins only, for example the GitHub issue number.',
	'ACP_BEYONDBOT_REPORT_GITHUB' => 'Create GitHub issue',
	'ACP_BEYONDBOT_REPORT_SAVED' => 'Report saved.',
	'ACP_BEYONDBOT_REPORT_DELETED' => 'Report deleted.',
	'ACP_BEYONDBOT_REPORT_DELETE_CONFIRM' => 'Delete this report? The forum post is not touched.',
	'ACP_BEYONDBOT_REPORT_STATUS_NEW' => 'New',
	'ACP_BEYONDBOT_REPORT_STATUS_CONFIRMED' => 'Confirmed',
	'ACP_BEYONDBOT_REPORT_STATUS_FILED' => 'Filed on GitHub',
	'ACP_BEYONDBOT_REPORT_STATUS_DONE' => 'Fixed',
	'ACP_BEYONDBOT_REPORT_STATUS_NOT_A_BUG' => 'Not a bug',
	'ACP_BEYONDBOT_REPORT_STATUS_DUPLICATE' => 'Duplicate',
]);
