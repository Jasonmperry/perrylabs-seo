<?php
/**
 * PLSEO_AI_Crawlers — catalogue of AI/LLM crawlers and per-bot policy.
 *
 * Drives:
 *   - robots.txt User-agent / Disallow lines
 *   - request-time UA detection for the AI visit log
 *
 * Per-bot policy values:
 *   'allow'         → no rule emitted (default allow)
 *   'block'         → Disallow: /
 *   'block_training' → block bots that explicitly self-identify as training crawlers
 *                      (e.g. GPTBot, ClaudeBot, CCBot, Google-Extended, anthropic-ai)
 *                      while leaving "search/answer" bots like OAI-SearchBot,
 *                      PerplexityBot, ChatGPT-User permitted.
 *
 * Stored under `plseo_options['ai_crawlers']` as map<slug, 'allow'|'block'|'block_training'>.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_AI_Crawlers {

	private static ?self $instance = null;

	/**
	 * Catalogue of known AI/LLM crawlers.
	 *
	 * `purpose` is informational, used for grouping in the admin UI and for the
	 * `block_training` mode logic. `ua_substring` is the case-insensitive needle
	 * we match against the request UA for visit logging.
	 *
	 * @var array<string,array{label:string,operator:string,purpose:string,ua_substring:string,docs:string}>
	 */
	public const CATALOG = array(
		'gptbot' => array(
			'label'        => 'GPTBot',
			'operator'     => 'OpenAI',
			'purpose'      => 'training',
			'ua_substring' => 'GPTBot',
			'docs'         => 'https://platform.openai.com/docs/gptbot',
		),
		'chatgpt-user' => array(
			'label'        => 'ChatGPT-User',
			'operator'     => 'OpenAI',
			'purpose'      => 'on_demand',
			'ua_substring' => 'ChatGPT-User',
			'docs'         => 'https://platform.openai.com/docs/plugins/bot',
		),
		'oai-searchbot' => array(
			'label'        => 'OAI-SearchBot',
			'operator'     => 'OpenAI',
			'purpose'      => 'search',
			'ua_substring' => 'OAI-SearchBot',
			'docs'         => 'https://platform.openai.com/docs/bots',
		),
		'claudebot' => array(
			'label'        => 'ClaudeBot',
			'operator'     => 'Anthropic',
			'purpose'      => 'training',
			'ua_substring' => 'ClaudeBot',
			'docs'         => 'https://www.anthropic.com/news/transparent-and-controllable',
		),
		'anthropic-ai' => array(
			'label'        => 'anthropic-ai',
			'operator'     => 'Anthropic',
			'purpose'      => 'training',
			'ua_substring' => 'anthropic-ai',
			'docs'         => 'https://www.anthropic.com/news/transparent-and-controllable',
		),
		'claude-searchbot' => array(
			'label'        => 'Claude-SearchBot',
			'operator'     => 'Anthropic',
			'purpose'      => 'search',
			'ua_substring' => 'Claude-SearchBot',
			'docs'         => 'https://www.anthropic.com',
		),
		'claude-user' => array(
			'label'        => 'Claude-User',
			'operator'     => 'Anthropic',
			'purpose'      => 'on_demand',
			'ua_substring' => 'Claude-User',
			'docs'         => 'https://www.anthropic.com',
		),
		'perplexitybot' => array(
			'label'        => 'PerplexityBot',
			'operator'     => 'Perplexity',
			'purpose'      => 'search',
			'ua_substring' => 'PerplexityBot',
			'docs'         => 'https://docs.perplexity.ai/guides/bots',
		),
		'perplexity-user' => array(
			'label'        => 'Perplexity-User',
			'operator'     => 'Perplexity',
			'purpose'      => 'on_demand',
			'ua_substring' => 'Perplexity-User',
			'docs'         => 'https://docs.perplexity.ai/guides/bots',
		),
		'google-extended' => array(
			'label'        => 'Google-Extended',
			'operator'     => 'Google',
			'purpose'      => 'training',
			'ua_substring' => 'Google-Extended',
			'docs'         => 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers',
		),
		'applebot-extended' => array(
			'label'        => 'Applebot-Extended',
			'operator'     => 'Apple',
			'purpose'      => 'training',
			'ua_substring' => 'Applebot-Extended',
			'docs'         => 'https://support.apple.com/en-us/119829',
		),
		'ccbot' => array(
			'label'        => 'CCBot',
			'operator'     => 'Common Crawl',
			'purpose'      => 'training',
			'ua_substring' => 'CCBot',
			'docs'         => 'https://commoncrawl.org/ccbot',
		),
		'meta-externalagent' => array(
			'label'        => 'Meta-ExternalAgent',
			'operator'     => 'Meta',
			'purpose'      => 'training',
			'ua_substring' => 'Meta-ExternalAgent',
			'docs'         => 'https://developers.facebook.com/docs/sharing/webmasters/crawler/',
		),
		'bytespider' => array(
			'label'        => 'Bytespider',
			'operator'     => 'ByteDance',
			'purpose'      => 'training',
			'ua_substring' => 'Bytespider',
			'docs'         => 'https://bytespider.bytedance.com',
		),
		'duckassistbot' => array(
			'label'        => 'DuckAssistBot',
			'operator'     => 'DuckDuckGo',
			'purpose'      => 'on_demand',
			'ua_substring' => 'DuckAssistBot',
			'docs'         => 'https://duckduckgo.com/duckduckbot',
		),
		'youbot' => array(
			'label'        => 'YouBot',
			'operator'     => 'You.com',
			'purpose'      => 'search',
			'ua_substring' => 'YouBot',
			'docs'         => 'https://you.com',
		),
		'amazonbot' => array(
			'label'        => 'Amazonbot',
			'operator'     => 'Amazon',
			'purpose'      => 'search',
			'ua_substring' => 'Amazonbot',
			'docs'         => 'https://developer.amazon.com/amazonbot',
		),
		'cohere-ai' => array(
			'label'        => 'cohere-ai',
			'operator'     => 'Cohere',
			'purpose'      => 'training',
			'ua_substring' => 'cohere-ai',
			'docs'         => 'https://cohere.com',
		),
	);

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		// On first run after a v1 install with "block all AI crawlers" on,
		// migrate that into per-bot policy.
		$opts = PLSEO_Options::all();
		if ( ! empty( $opts['_v1_blocked_ai_crawlers'] ) ) {
			$policy = $this->policy();
			foreach ( self::CATALOG as $slug => $_ ) {
				$policy[ $slug ] = 'block';
			}
			PLSEO_Options::update( array(
				'ai_crawlers'            => $policy,
				'_v1_blocked_ai_crawlers' => false,
			) );
		}
	}

	/**
	 * Currently saved per-bot policy. Missing bots default to 'allow'.
	 *
	 * @return array<string,string>
	 */
	public function policy(): array {
		$raw = PLSEO_Options::get( 'ai_crawlers', array() );
		return is_array( $raw ) ? $raw : array();
	}

	public function policy_for( string $slug ): string {
		$p = $this->policy();
		return $p[ $slug ] ?? 'allow';
	}

	/**
	 * Resolve an incoming User-Agent string to a known bot slug, or null.
	 */
	public function match_user_agent( string $ua ): ?string {
		if ( '' === $ua ) {
			return null;
		}
		foreach ( self::CATALOG as $slug => $row ) {
			if ( stripos( $ua, $row['ua_substring'] ) !== false ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Build the AI-bot rules block for robots.txt output. Returned as plain text
	 * lines, no leading/trailing blank lines.
	 */
	public function robots_block(): string {
		$policy = $this->policy();
		$blocks = array();

		foreach ( self::CATALOG as $slug => $bot ) {
			$rule = $policy[ $slug ] ?? 'allow';

			if ( 'allow' === $rule ) {
				continue;
			}

			$block_training_active = 'block_training' === $rule && 'training' === $bot['purpose'];
			$full_block_active     = 'block' === $rule;

			if ( ! $full_block_active && ! $block_training_active ) {
				continue;
			}

			$blocks[] = "User-agent: {$bot['label']}\nDisallow: /";
		}

		return implode( "\n\n", $blocks );
	}
}
