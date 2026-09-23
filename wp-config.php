<?php
define( 'WP_CACHE', true );

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'minyulab' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '!0$jR0_F9d*3FU#E,&Ouz{Dr,#-,UdVF@BbhsvV/FpE)Th9hPLPEf7P A@v6fMp<' );
define( 'SECURE_AUTH_KEY',  ')lTyto8[0SULaw=0})6j7;Sxb{u?-!2Ny7tY|aogk}hjvnv<LqxOY1}d7F2<0}(`' );
define( 'LOGGED_IN_KEY',    '3G_ HmrAe{i~`CH}84E)96LI![jc-ayD9jx[ma|1Qzy;7{@qXyF@Bu1^v4=3Sf`Y' );
define( 'NONCE_KEY',        ']GB8Pc][x0El_*@yP!.VGg ~~/$B5c$a{Zh[e)Hbui.Q{~g <#%QreH=`g1H]pm^' );
define( 'AUTH_SALT',        'yHQi7.=(l-:U#Wmx?W]j*?MP4-nc25[|8]:pa*veAA$v->Wd>6{-Nk|$cdM,{hM6' );
define( 'SECURE_AUTH_SALT', '8-OpCmd9U^Rb;H8]&XSiR`)9n0gb=eP?#)9gU6A,k>lI.B{{f1;{SE0M>bF$D#79' );
define( 'LOGGED_IN_SALT',   'r*+Lp%6XDnQ`<Y^cR-*A{-bn&pT8lDtb*qt,IR0v(DMHl~TOzvlmVw*vQA;>@Scq' );
define( 'NONCE_SALT',       'u=[-*P#^#x5J^t~7#`/.=25PUgZbf15s)G$7z26=7&{F(x_by;isY]KN#n8s~._i' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
