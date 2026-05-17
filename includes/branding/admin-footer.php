<?php
/**
 * PerryLabs admin footer partial.
 *
 * Source: /_ops/branding/admin-footer.php
 *
 * Usage:
 *   require __DIR__ . '/branding/admin-footer.php';
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pl_logo  = ( class_exists( 'PerryLabs_Branding' ) && method_exists( 'PerryLabs_Branding', 'logo_url' ) )
	? PerryLabs_Branding::logo_url()
	: 'https://perrylabs-assets.s3.us-east-1.amazonaws.com/PerryLabs-LogoMark.png';
$pl_url   = 'https://perrylabs.io';
$jmp_url  = 'https://jasonmperry.com';
?>
<div class="pl-admin-footer">
    <a class="pl-built-by" href="<?php echo esc_url( $pl_url ); ?>" target="_blank" rel="noopener noreferrer">
        <img src="<?php echo esc_url( $pl_logo ); ?>" alt="PerryLabs" />
        <span>Built by PerryLabs</span>
    </a>
    <span class="pl-sep">|</span>
    <a href="<?php echo esc_url( $jmp_url ); ?>" target="_blank" rel="noopener noreferrer">jasonmperry.com</a>
</div>
