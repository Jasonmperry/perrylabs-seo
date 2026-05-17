<?php
/**
 * PLSEO_AI_Fill — AI-generated SEO meta for posts that have none.
 *
 * Used exclusively by the WP-CLI `wp plseo ai-fill` command (see PLSEO_CLI).
 * Encapsulates the Claude API call + prompt + JSON response parsing so the CLI
 * code stays thin.
 *
 * API key resolution order:
 *   1. PLSEO_ANTHROPIC_API_KEY  — define in wp-config.php
 *   2. ANTHROPIC_API_KEY environment variable
 *   3. plseo_options['ai_fill_api_key'] (admin-settable; not exposed in UI by default)
 *
 * Never logs the key. Never sends it anywhere except api.anthropic.com.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_AI_Fill {

	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	private const MODEL    = 'claude-haiku-4-5-20251001'; // fast + cheap, plenty for SEO meta
	private const VERSION  = '2023-06-01';

	/**
	 * Resolve the API key from any of the supported sources.
	 */
	public static function api_key(): string {
		if ( defined( 'PLSEO_ANTHROPIC_API_KEY' ) ) {
			$key = (string) constant( 'PLSEO_ANTHROPIC_API_KEY' );
			if ( '' !== $key ) {
				return $key;
			}
		}
		$env = getenv( 'ANTHROPIC_API_KEY' );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}
		return (string) PLSEO_Options::get( 'ai_fill_api_key', '' );
	}

	/**
	 * Generate SEO meta for one post.
	 *
	 * @return array{title:string,description:string,focus_keyword:string,quick_answer:string}|\WP_Error
	 */
	public static function generate_for_post( \WP_Post $post ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new \WP_Error(
				'plseo_ai_fill_no_key',
				'No Claude API key found. Set PLSEO_ANTHROPIC_API_KEY in wp-config.php, ANTHROPIC_API_KEY env var, or plseo_options[ai_fill_api_key].'
			);
		}

		$prompt = self::build_prompt( $post );

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 30,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => self::VERSION,
				'content-type'      => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'      => self::MODEL,
				'max_tokens' => 800,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'plseo_ai_fill_http_' . $code,
				sprintf( 'Claude API returned HTTP %d: %s', $code, mb_substr( $body, 0, 400 ) )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'plseo_ai_fill_bad_json', 'Claude API response was not JSON' );
		}

		// The response shape is { content: [ { type: "text", text: "<our json>" } ] }.
		$text = '';
		foreach ( (array) ( $decoded['content'] ?? array() ) as $chunk ) {
			if ( is_array( $chunk ) && ( $chunk['type'] ?? '' ) === 'text' ) {
				$text .= (string) ( $chunk['text'] ?? '' );
			}
		}
		if ( '' === $text ) {
			return new \WP_Error( 'plseo_ai_fill_empty', 'Claude API returned no text content' );
		}

		$parsed = self::parse_model_output( $text );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		return $parsed;
	}

	/**
	 * Build the user-message prompt that asks for strict JSON output.
	 */
	private static function build_prompt( \WP_Post $post ): string {
		$site_name = (string) get_bloginfo( 'name' );
		$tagline   = (string) get_bloginfo( 'description' );
		$plain     = PLSEO_Str::post_plain_text( $post );
		// Cap prompt content so we keep the request small and cheap.
		$snippet   = mb_substr( $plain, 0, 2500 );

		return <<<PROMPT
You are drafting SEO meta for a single page on {$site_name} ({$tagline}).

POST TITLE: {$post->post_title}

POST CONTENT (truncated to 2500 chars):
---
{$snippet}
---

Return ONLY a JSON object with exactly these four string keys, no additional commentary:

{
  "title": "SEO title, 35-60 chars, includes the site or brand suffix where it fits. Sentence case.",
  "description": "Meta description, 130-160 chars. Active voice. Concrete. No marketing fluff.",
  "focus_keyword": "Lowercase 2-5 word focus keyword that best matches search intent for this post.",
  "quick_answer": "2-3 sentence summary that AI overview engines could lift verbatim as the answer to 'what is this page about'. Specific, declarative, no leading 'This article'."
}
PROMPT;
	}

	/**
	 * Parse the model's JSON output. Tolerant of leading prose or code-fence
	 * wrappers; greedy-matches the first {...} block.
	 *
	 * @return array{title:string,description:string,focus_keyword:string,quick_answer:string}|\WP_Error
	 */
	private static function parse_model_output( string $text ) {
		// Find the first { and the last } and try to decode that span.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end < $start ) {
			return new \WP_Error( 'plseo_ai_fill_no_json', 'Model output had no JSON object' );
		}
		$json    = substr( $text, $start, $end - $start + 1 );
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'plseo_ai_fill_unparseable_json', 'Model JSON did not decode: ' . mb_substr( $json, 0, 200 ) );
		}
		$out = array(
			'title'         => trim( (string) ( $decoded['title']         ?? '' ) ),
			'description'   => trim( (string) ( $decoded['description']   ?? '' ) ),
			'focus_keyword' => trim( (string) ( $decoded['focus_keyword'] ?? '' ) ),
			'quick_answer'  => trim( (string) ( $decoded['quick_answer']  ?? '' ) ),
		);
		if ( '' === $out['title'] || '' === $out['description'] ) {
			return new \WP_Error( 'plseo_ai_fill_incomplete', 'Model output missing title or description' );
		}
		return $out;
	}

	/**
	 * Persist generated meta. Honors --overwrite logic from the caller.
	 *
	 * @param array{title:string,description:string,focus_keyword:string,quick_answer:string} $meta
	 */
	public static function persist( int $post_id, array $meta ): void {
		update_post_meta( $post_id, '_plseo_title',         sanitize_text_field( $meta['title'] ) );
		update_post_meta( $post_id, '_plseo_description',   sanitize_textarea_field( $meta['description'] ) );
		update_post_meta( $post_id, '_plseo_focus_keyword', sanitize_text_field( $meta['focus_keyword'] ) );
		update_post_meta( $post_id, '_plseo_quick_answer',  sanitize_textarea_field( $meta['quick_answer'] ) );
	}
}
