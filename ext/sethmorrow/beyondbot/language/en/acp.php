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
	'ACP_BEYONDBOT_FORUMS_EXPLAIN' => 'Only these forums. Subforums are not included unless chosen too. Hold Ctrl (Cmd on a Mac) to choose more than one.',
	'ACP_BEYONDBOT_GROUPS' => 'Who may ask',
	'ACP_BEYONDBOT_GROUPS_EXPLAIN' => 'Members of any chosen group. Choose none to let any member ask.',
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
]);
