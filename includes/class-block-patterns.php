<?php
/**
 * PLSEO_Block_Patterns — register starter block patterns for AEO content.
 *
 * Two patterns:
 *
 *   1. "FAQ section" — three core/details blocks pre-populated with placeholder
 *      Q/A pairs. Authors edit in place; the FAQPage schema auto-emits because
 *      PLSEO_Schema_AEO walks core/details blocks looking for <summary> + body.
 *
 *   2. "Step-by-step how-to" — an H2 + numbered core/list. The HowTo schema
 *      auto-emits because PLSEO_Schema_AEO recognizes the first <ol> as a step
 *      list.
 *
 * Patterns appear in the block inserter under a "PerryLabs SEO" category.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Block_Patterns {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		if ( function_exists( 'register_block_pattern_category' ) ) {
			register_block_pattern_category(
				'perrylabs-seo',
				array(
					'label'       => __( 'PerryLabs SEO', 'perrylabs-seo' ),
					'description' => __( 'Patterns that the PerryLabs SEO + AEO plugin can auto-detect for FAQPage / HowTo / Q&A schema.', 'perrylabs-seo' ),
				)
			);
		}

		register_block_pattern(
			'perrylabs-seo/faq-section',
			array(
				'title'       => __( 'FAQ section (AEO-ready)', 'perrylabs-seo' ),
				'description' => _x( 'Three expandable Q&A pairs. The PerryLabs SEO plugin auto-emits FAQPage schema when at least one details block is present.', 'Block pattern description', 'perrylabs-seo' ),
				'categories'  => array( 'perrylabs-seo' ),
				'keywords'    => array( 'faq', 'frequently asked questions', 'aeo', 'questions' ),
				'content'     => self::faq_content(),
			)
		);

		register_block_pattern(
			'perrylabs-seo/howto-steps',
			array(
				'title'       => __( 'Step-by-step how-to (AEO-ready)', 'perrylabs-seo' ),
				'description' => _x( 'Heading plus numbered list. The PerryLabs SEO plugin auto-emits HowTo schema for the first ordered list after a heading.', 'Block pattern description', 'perrylabs-seo' ),
				'categories'  => array( 'perrylabs-seo' ),
				'keywords'    => array( 'how-to', 'steps', 'tutorial', 'aeo' ),
				'content'     => self::howto_content(),
			)
		);

		register_block_pattern(
			'perrylabs-seo/quick-answer',
			array(
				'title'       => __( 'Quick answer / TL;DR (AEO-ready)', 'perrylabs-seo' ),
				'description' => _x( 'A pull-quote box at the top of an article that AI overviews tend to lift verbatim as the answer.', 'Block pattern description', 'perrylabs-seo' ),
				'categories'  => array( 'perrylabs-seo' ),
				'keywords'    => array( 'tldr', 'summary', 'answer', 'aeo', 'speakable' ),
				'content'     => self::quick_answer_content(),
			)
		);
	}

	private static function faq_content(): string {
		return <<<HTML
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Frequently asked questions</h2>
<!-- /wp:heading -->

<!-- wp:details -->
<details class="wp-block-details"><summary>What does this product do?</summary>
<!-- wp:paragraph -->
<p>Replace this paragraph with a clear, complete answer. Two or three sentences is plenty. Be specific — vague answers don't get cited by AI overviews.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary>How much does it cost?</summary>
<!-- wp:paragraph -->
<p>State the answer plainly. If the answer is "it depends," explain what it depends on with one concrete example.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary>Who is it for?</summary>
<!-- wp:paragraph -->
<p>Name the audience. Be specific about who's a fit and who isn't — exclusion clarifies inclusion.</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->
HTML;
	}

	private static function howto_content(): string {
		return <<<HTML
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">How to do the thing</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>One-sentence intro framing what the reader will accomplish.</p>
<!-- /wp:paragraph -->

<!-- wp:list {"ordered":true} -->
<ol><!-- wp:list-item -->
<li>First step. Be concrete — name the menu, button, or command.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Second step. If you'd say "see screenshot above" verbally, link or describe it inline instead.</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Third step. Use the imperative voice: "Click", "Open", "Save".</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Final step. Tell the reader what success looks like.</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->
HTML;
	}

	private static function quick_answer_content(): string {
		return <<<HTML
<!-- wp:pullquote {"className":"speakable"} -->
<figure class="wp-block-pullquote speakable"><blockquote><p>Replace this with a two-to-three-sentence summary that directly answers the question your post is about. Specific, declarative, no leading "This article". This is the text AI overviews tend to lift verbatim.</p></blockquote></figure>
<!-- /wp:pullquote -->
HTML;
	}
}
