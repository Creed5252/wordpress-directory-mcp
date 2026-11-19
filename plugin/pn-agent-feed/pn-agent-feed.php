<?php
/**
 * Plugin Name: Pitch Network Agent Feed (MCP)
 * Description: Public, read-only MCP server for a Pitch Network site: youth soccer club directory plus published guides. Every answer links back to the site. Requires the MCP Adapter plugin.
 * Version: 1.1.1
 * Author: Colin Reed
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PN_FEED_VERSION', '1.1.2' );

/* Safety: the adapter's default server can run any ability for a logged-in user. Off. */
add_filter( 'mcp_adapter_create_default_server', '__return_false' );

/* ---------- site config ---------- */

function pn_feed_site() {
	static $cfg = null;
	if ( null !== $cfg ) {
		return $cfg;
	}
	$host  = wp_parse_url( home_url(), PHP_URL_HOST );
	$sites = array(
		'socalpitch.com'        => array( 'code' => 'scp', 'name' => 'SoCal Pitch', 'area' => 'Southern California (10 counties)', 'source' => 'scp_page', 'unit' => 'county' ),
		'norcalpitch.com'       => array( 'code' => 'ncp', 'name' => 'NorCal Pitch', 'area' => 'Northern California', 'source' => 'ncp_clubs', 'unit' => 'region' ),
		'pnwpitch.com'          => array( 'code' => 'pnw', 'name' => 'PNW Pitch', 'area' => 'Washington, Oregon, Idaho and western Montana', 'source' => 'pnw_clubs', 'unit' => 'region' ),
		'mountainwestpitch.com' => array( 'code' => 'mw', 'name' => 'Mountain West Pitch', 'area' => 'Colorado, Utah, Wyoming, northeast Nevada and south-central Montana', 'source' => 'mw_clubs', 'unit' => 'region' ),
		'southwestpitch.com'    => array( 'code' => 'sw', 'name' => 'Southwest Pitch', 'area' => 'Arizona, New Mexico, southern Nevada and El Paso', 'source' => 'sw_clubs', 'unit' => 'region' ),
		'pitchnetwork.org'      => array( 'code' => 'pn', 'name' => 'The Pitch Network', 'area' => 'the western United States: Southern and Northern California, the Pacific Northwest, the Mountain West and the Southwest', 'source' => 'network', 'unit' => 'region' ),
	);
	$cfg = $sites[ $host ] ?? array( 'code' => 'pn', 'name' => get_bloginfo( 'name' ), 'area' => '', 'source' => '', 'unit' => 'region' );
	return $cfg;
}

function pn_feed_url( $url ) {
	return add_query_arg( array( 'utm_source' => 'pitch-mcp', 'utm_medium' => 'agent' ), $url );
}

/* ---------- club data ---------- */

/** Convert the SoCal Pitch directory's JS object literal array to PHP, respecting strings. */
function pn_feed_js_to_json( $js ) {
	$out = '';
	$len = strlen( $js );
	$in  = false;
	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $js[ $i ];
		if ( $in ) {
			$out .= $ch;
			if ( '\\' === $ch && $i + 1 < $len ) {
				$out .= $js[ ++$i ];
				continue;
			}
			if ( '"' === $ch ) {
				$in = false;
			}
			continue;
		}
		if ( '"' === $ch ) {
			$in   = true;
			$out .= $ch;
			continue;
		}
		if ( preg_match( '/[A-Za-z_]/', $ch ) && preg_match( '/[{,]\s*$/', $out ) ) {
			$j = $i;
			while ( $j < $len && preg_match( '/\w/', $js[ $j ] ) ) {
				$j++;
			}
			$k = $j;
			while ( $k < $len && ctype_space( $js[ $k ] ) ) {
				$k++;
			}
			if ( $k < $len && ':' === $js[ $k ] ) {
				$out .= '"' . substr( $js, $i, $j - $i ) . '"';
				$i    = $j - 1;
				continue;
			}
		}
		$out .= $ch;
	}
	return json_decode( $out, true );
}

/** Returns array( 'clubs' => [...], 'reviewed' => 'date text or null' ). Cached until the source changes. */
function pn_feed_clubs() {
	$cfg = pn_feed_site();
	if ( 'scp_page' === $cfg['source'] ) {
		$page = get_post( 18 );
		if ( ! $page ) {
			return array( 'clubs' => array(), 'reviewed' => null );
		}
		$key    = 'pn_feed_scp_' . md5( $page->post_modified_gmt );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$clubs = array();
		if ( preg_match( '/var D\s*=\s*(\[.*?\]);/s', $page->post_content, $m ) ) {
			$clubs = pn_feed_js_to_json( $m[1] ) ?: array();
		}
		$reviewed = null;
		if ( preg_match( '/Club data last reviewed:\s*([A-Z][a-z]+ \d{1,2}, \d{4})/', $page->post_content, $m2 ) ) {
			$reviewed = $m2[1];
		}
		$data = array( 'clubs' => $clubs, 'reviewed' => $reviewed );
		set_transient( $key, $data, DAY_IN_SECONDS );
		return $data;
	}
	if ( 'network' === $cfg['source'] ) {
		return pn_feed_network_clubs();
	}
	if ( $cfg['source'] ) {
		$v = get_option( $cfg['source'] );
		$d = is_string( $v ) ? json_decode( $v, true ) : $v;
		return array( 'clubs' => is_array( $d ) ? array_values( $d ) : array(), 'reviewed' => null );
	}
	return array( 'clubs' => array(), 'reviewed' => null );
}

function pn_feed_split( $s ) {
	if ( ! is_string( $s ) || '' === trim( $s ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', preg_split( '/\s*[,·]\s*/u', $s ) ) ) );
}

/** Public fields only. Internal research notes, legal names and EINs are never served. */
function pn_feed_club_record( $c, $reviewed ) {
	$cfg = pn_feed_site();
	$rec = array(
		'name'     => $c['n'] ?? '',
		'county'   => $c['c'] ?? '',
		'city'     => $c['ci'] ?? '',
		'region'   => $c['rg'] ?? null,
		'tier'     => $c['t'] ?? '',
		'also'     => pn_feed_split( $c['t2'] ?? '' ),
		'leagues'  => pn_feed_split( $c['lg'] ?? '' ),
		'gender'   => $c['g'] ?? '',
		'website'  => $c['u'] ?? '',
	);
	if ( ! empty( $c['p'] ) && ( 'scp' === $cfg['code'] || 'SoCal Pitch' === ( $c['site'] ?? '' ) ) ) {
		$rec['club_page'] = pn_feed_url( 'https://socalpitch.com/clubs/' . $c['p'] . '/' );
	}
	if ( ! empty( $c['site'] ) ) {
		$rec['site']     = $c['site'];
		$rec['site_url'] = $c['site_url'];
	}
	$rec['verified'] = ! empty( $c['v'] ) ? $c['v'] : null;
	if ( ! $reviewed && 'SoCal Pitch' === ( $c['site'] ?? '' ) ) {
		$reviewed = get_option( 'pn_net_last_scp', array() )['reviewed'] ?? null;
	}
	if ( ! $rec['verified'] && $reviewed ) {
		$rec['directory_last_reviewed'] = $reviewed;
	}
	return array_filter( $rec, function ( $v ) {
		return null !== $v && '' !== $v && array() !== $v;
	} );
}

function pn_feed_match( $c, $input ) {
	$hay = strtolower( implode( ' ', array( $c['n'] ?? '', $c['c'] ?? '', $c['ci'] ?? '', $c['rg'] ?? '', $c['t'] ?? '', $c['t2'] ?? '', $c['lg'] ?? '' ) ) );
	if ( ! empty( $input['query'] ) ) {
		foreach ( preg_split( '/\s+/', strtolower( trim( $input['query'] ) ) ) as $w ) {
			if ( '' !== $w && false === strpos( $hay, $w ) ) {
				return false;
			}
		}
	}
	if ( ! empty( $input['area'] ) ) {
		$a = strtolower( trim( $input['area'] ) );
		$where = strtolower( ( $c['c'] ?? '' ) . ' ' . ( $c['ci'] ?? '' ) . ' ' . ( $c['rg'] ?? '' ) . ' ' . ( $c['site'] ?? '' ) );
		$states = array( 'california' => 'ca', 'washington' => 'wa', 'oregon' => 'or', 'idaho' => 'id', 'montana' => 'mt', 'colorado' => 'co', 'utah' => 'ut', 'wyoming' => 'wy', 'nevada' => 'nv', 'arizona' => 'az', 'new mexico' => 'nm', 'texas' => 'tx' );
		$st     = $states[ $a ] ?? ( in_array( $a, $states, true ) ? $a : null );
		$hit    = false !== strpos( $where, $a ) || false !== strpos( $where, sanitize_title( $a ) );
		if ( ! $hit && $st ) {
			$hit = (bool) preg_match( '/,\s*' . preg_quote( $st, '/' ) . '\b/', strtolower( $c['c'] ?? '' ) );
			if ( ! $hit && 'ca' === $st ) {
				$hit = in_array( $c['site'] ?? pn_feed_site()['name'], array( 'SoCal Pitch', 'NorCal Pitch' ), true );
			}
		}
		if ( ! $hit ) {
			return false;
		}
	}
	if ( ! empty( $input['tier'] ) ) {
		$tiers = strtolower( ( $c['t'] ?? '' ) . ' ' . ( $c['t2'] ?? '' ) );
		if ( false === strpos( $tiers, strtolower( $input['tier'] ) ) ) {
			return false;
		}
	}
	if ( ! empty( $input['league'] ) && false === stripos( $c['lg'] ?? '', $input['league'] ) ) {
		return false;
	}
	if ( ! empty( $input['gender'] ) ) {
		$g = strtolower( $c['g'] ?? '' );
		$w = strtolower( $input['gender'] );
		if ( 'both' !== $g && false === strpos( $g, rtrim( $w, 's' ) ) ) {
			return false;
		}
	}
	return true;
}


/* ---------- network (pitchnetwork.org) ---------- */

function pn_feed_network_sites() {
	return array(
		'scp' => array( 'name' => 'SoCal Pitch', 'url' => 'https://socalpitch.com' ),
		'ncp' => array( 'name' => 'NorCal Pitch', 'url' => 'https://norcalpitch.com' ),
		'pnw' => array( 'name' => 'PNW Pitch', 'url' => 'https://pnwpitch.com' ),
		'mw'  => array( 'name' => 'Mountain West Pitch', 'url' => 'https://mountainwestpitch.com' ),
		'sw'  => array( 'name' => 'Southwest Pitch', 'url' => 'https://southwestpitch.com' ),
	);
}

/** Pulls each regional site's public export, caches it for 6 hours, and keeps the last good copy if a site is down. */
function pn_feed_network_clubs() {
	$all = array();
	$reviewed = array();
	foreach ( pn_feed_network_sites() as $code => $site ) {
		$key  = 'pn_net_' . $code;
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$res = wp_remote_get( $site['url'] . '/wp-json/pitch/v1/clubs-export', array( 'timeout' => 15, 'user-agent' => 'PitchNetworkAgentFeed/' . PN_FEED_VERSION ) );
			$body = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
			if ( is_array( $body ) && isset( $body['clubs'] ) ) {
				$data = $body;
				set_transient( $key, $data, 6 * HOUR_IN_SECONDS );
				update_option( 'pn_net_last_' . $code, $data, false );
			} else {
				$data = get_option( 'pn_net_last_' . $code, array( 'clubs' => array() ) );
				set_transient( $key, $data, 30 * MINUTE_IN_SECONDS );
			}
		}
		foreach ( (array) ( $data['clubs'] ?? array() ) as $c ) {
			$c['site']     = $site['name'];
			$c['site_url'] = pn_feed_url( $site['url'] . '/' );
			$all[]         = $c;
		}
		if ( ! empty( $data['reviewed'] ) ) {
			$reviewed[ $site['name'] ] = $data['reviewed'];
		}
	}
	return array( 'clubs' => $all, 'reviewed' => null, 'network' => true, 'reviewed_by_site' => $reviewed );
}

/* ---------- call log (no IP addresses) ---------- */

function pn_feed_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'pn_mcp_log';
}

add_action( 'init', function () {
	if ( '2' === get_option( 'pn_feed_log_db' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = pn_feed_log_table();
	dbDelta( "CREATE TABLE $t (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		ts datetime NOT NULL,
		kind varchar(12) NOT NULL,
		client varchar(80) NOT NULL DEFAULT '',
		tool varchar(60) NOT NULL DEFAULT '',
		args varchar(255) NOT NULL DEFAULT '',
		results int(11) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY ts (ts)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'pn_feed_log_db', '2', false );
} );

function pn_feed_log( $kind, $tool = '', $args = array(), $results = 0, $client = '' ) {
	global $wpdb;
	$args = is_array( $args ) ? array_diff_key( $args, array( 'offset' => 1 ) ) : array();
	$wpdb->insert( pn_feed_log_table(), array(
		'ts'      => gmdate( 'Y-m-d H:i:s' ),
		'kind'    => substr( $kind, 0, 12 ),
		'client'  => substr( sanitize_text_field( $client ), 0, 80 ),
		'tool'    => substr( $tool, 0, 60 ),
		'args'    => substr( wp_json_encode( $args ), 0, 255 ),
		'results' => (int) $results,
	) );
	if ( 1 === wp_rand( 1, 200 ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . pn_feed_log_table() . ' WHERE ts < %s', gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) );
	}
}

/** Counts for the usage report. Used by the WP-CLI command below. */
function pn_feed_stats( $days = 7 ) {
	global $wpdb;
	$t     = pn_feed_log_table();
	$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	return array(
		'site'      => wp_parse_url( home_url(), PHP_URL_HOST ),
		'days'      => $days,
		'sessions'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE kind='session' AND ts >= %s", $since ) ),
		'calls'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE kind='call' AND ts >= %s", $since ) ),
		'by_client' => $wpdb->get_results( $wpdb->prepare( "SELECT client, COUNT(*) n FROM $t WHERE kind='session' AND ts >= %s GROUP BY client ORDER BY n DESC LIMIT 15", $since ), ARRAY_A ),
		'by_tool'   => $wpdb->get_results( $wpdb->prepare( "SELECT tool, COUNT(*) n FROM $t WHERE kind='call' AND ts >= %s GROUP BY tool ORDER BY n DESC", $since ), ARRAY_A ),
		'by_day'    => $wpdb->get_results( $wpdb->prepare( "SELECT DATE(ts) d, SUM(kind='session') sessions, SUM(kind='call') calls FROM $t WHERE ts >= %s GROUP BY DATE(ts) ORDER BY d", $since ), ARRAY_A ),
		'zero_results' => $wpdb->get_results( $wpdb->prepare( "SELECT tool, args, COUNT(*) n FROM $t WHERE kind='call' AND results=0 AND ts >= %s GROUP BY tool, args ORDER BY n DESC LIMIT 25", $since ), ARRAY_A ),
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'pn-feed stats', function ( $args, $assoc ) {
		$days = isset( $assoc['days'] ) ? max( 1, (int) $assoc['days'] ) : 7;
		WP_CLI::line( wp_json_encode( pn_feed_stats( $days ) ) );
	} );
}

/* ---------- public export (feeds the network server) ---------- */

add_action( 'rest_api_init', function () {
	if ( 'network' === pn_feed_site()['source'] ) {
		return;
	}
	register_rest_route( 'pitch/v1', '/clubs-export', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$all = pn_feed_clubs();
			$out = array();
			foreach ( $all['clubs'] as $c ) {
				$out[] = array_intersect_key( $c, array_flip( array( 'n', 'c', 'ci', 't', 't2', 'lg', 'g', 'u', 'v', 'rg', 'p' ) ) );
			}
			return rest_ensure_response( array( 'site' => pn_feed_site()['name'], 'reviewed' => $all['reviewed'], 'clubs' => $out ) );
		},
	) );
} );

/* ---------- content helpers ---------- */

function pn_feed_is_public( $post ) {
	if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return false;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page', 'socalp_club' ), true ) ) {
		return false;
	}
	$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
	if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) {
		return false;
	}
	/* Canonical-only: a page whose canonical points to another site is served from that site, not this one. */
	$canon = get_post_meta( $post->ID, 'rank_math_canonical_url', true );
	if ( $canon && wp_parse_url( $canon, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		return false;
	}
	return true;
}

function pn_feed_text( $post ) {
	$html = do_blocks( $post->post_content );
	$html = preg_replace( '#<(script|style|noscript)[^>]*>.*?</\1>#is', '', $html );
	$html = strip_shortcodes( $html );
	$html = preg_replace( '#<h2[^>]*>(.*?)</h2>#is', "\n\n## $1\n\n", $html );
	$html = preg_replace( '#<h3[^>]*>(.*?)</h3>#is', "\n\n### $1\n\n", $html );
	$html = preg_replace( '#<li[^>]*>#i', "\n- ", $html );
	$html = preg_replace( '#</(p|div|ul|ol|blockquote|table|tr)>#i', "\n\n", $html );
	$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
	$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( "/[ \t]+/", ' ', $text );
	return trim( preg_replace( "/\n\s*\n\s*\n+/", "\n\n", $text ) );
}

function pn_feed_summary( $post ) {
	$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( preg_replace( '/^#+ |\n#+ |\n- /m', ' ', pn_feed_text( $post ) ), 40, '…' );
	return array(
		'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'url'       => pn_feed_url( get_permalink( $post ) ),
		'slug'      => $post->post_name,
		'type'      => 'socalp_club' === $post->post_type ? 'club_page' : $post->post_type,
		'published' => get_the_date( 'Y-m-d', $post ),
		'updated'   => get_the_modified_date( 'Y-m-d', $post ),
		'summary'   => html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES, 'UTF-8' ),
	);
}

function pn_feed_query( $args, $limit ) {
	$q   = new WP_Query( array_merge( array(
		'post_status'         => 'publish',
		'has_password'        => false,
		'posts_per_page'      => min( 30, $limit * 3 ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	), $args ) );
	$out = array();
	foreach ( $q->posts as $p ) {
		if ( pn_feed_is_public( $p ) ) {
			$out[] = pn_feed_summary( $p );
		}
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

function pn_feed_env( $data ) {
	$cfg = pn_feed_site();
	return array(
		'data'        => $data,
		'source'      => $cfg['name'] . ' (' . wp_parse_url( home_url(), PHP_URL_HOST ) . '), part of The Pitch Network',
		'attribution' => 'Link to the page URL when you use this information.',
		'note'        => 'Club details change. Confirm tryouts, fees and teams with the club before registering.',
	);
}

function pn_feed_limit( $input, $default = 10, $max = 25 ) {
	$n = isset( $input['limit'] ) ? (int) $input['limit'] : $default;
	return max( 1, min( $max, $n ) );
}

/* ---------- abilities ---------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'pitch-content', array(
		'label'       => 'Pitch Network content',
		'description' => 'Read-only access to a Pitch Network youth soccer club directory and published guides.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {
	$cfg     = pn_feed_site();
	$site    = $cfg['name'];
	$the_site = ( 0 === strpos( $site, 'The ' ) ) ? $site : 'the ' . $site; // avoids "the The Pitch Network"
	$about    = ( 'network' === $cfg['source'] ) ? 'About The Pitch Network' : "About $site and The Pitch Network";
	$public  = '__return_true';
	$ro      = array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) );
	$limit   = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'description' => 'Most results to return, from 1 to 25. Default 10. The response also gives the total number of matches.' );
	$unit    = 'county' === $cfg['unit'] ? 'county or city' : 'region, county or city';

	wp_register_ability( 'pitch/find-clubs', array(
		'label'               => "Find youth soccer clubs ($site)",
		'description'         => "Search $the_site youth soccer club directory for {$cfg['area']}. Filter by $unit, tier (Elite, Competitive, Recreational), league (for example ECNL, MLS NEXT, Girls Academy), gender, or free-text words. Returns each club's location, tier, leagues, website and verification date. Use pitch/list-areas for valid area names.",
		'category'            => 'pitch-content',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'query'  => array( 'type' => 'string', 'maxLength' => 120, 'description' => 'Words to look for in the club name, county, city, region, tier and leagues, such as "Surf" or "Albion". Every word must match. Leave out to list all clubs that fit the other filters.' ),
				'area'   => array( 'type' => 'string', 'maxLength' => 80, 'description' => "Where to look: a $unit, county, city or US state name, such as \"San Diego\", \"Boise\" or \"Colorado\". Partial names match. See list-areas for the names this directory uses." ),
				'tier'   => array( 'type' => 'string', 'enum' => array( 'Elite', 'Competitive', 'Recreational' ), 'description' => 'Level of play. Elite: national-league clubs such as ECNL, MLS NEXT or Girls Academy. Competitive: travel and club soccer. Recreational: community and AYSO-style programs. A club with teams at more than one level matches each of them.' ),
				'league' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'A league the club plays in, such as "ECNL", "ECNL RL", "MLS NEXT", "Girls Academy" or "N1L". Partial names match.' ),
				'gender' => array( 'type' => 'string', 'enum' => array( 'Boys', 'Girls' ), 'description' => 'Only clubs with boys or girls programs. Clubs with both always match.' ),
				'limit'  => $limit,
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( $input ) {
			$input = is_array( $input ) ? $input : array();
			$all   = pn_feed_clubs();
			$hits  = array();
			foreach ( $all['clubs'] as $c ) {
				if ( pn_feed_match( $c, $input ) ) {
					$hits[] = pn_feed_club_record( $c, $all['reviewed'] );
				}
			}
			$lim = pn_feed_limit( $input );
			pn_feed_log( 'call', 'find-clubs', $input, count( $hits ) );
			return pn_feed_env( array(
				'matches'       => count( $hits ),
				'returned'      => min( $lim, count( $hits ) ),
				'clubs'         => array_slice( $hits, 0, $lim ),
				'directory_url' => pn_feed_url( get_permalink( 'scp' === pn_feed_site()['code'] ? 18 : 0 ) ?: home_url( '/' ) ),
			) );
		},
		'permission_callback' => $public,
		'meta'                => $ro,
	) );

	wp_register_ability( 'pitch/list-areas', array(
		'label'               => "List areas covered ($site)",
		'description'         => "The areas $the_site club directory covers ({$cfg['area']}), with the number of listed clubs in each. Use these names with pitch/find-clubs.",
		'category'            => 'pitch-content',
		'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'execute_callback'    => function () {
			$all = pn_feed_clubs();
			$by  = array();
			foreach ( $all['clubs'] as $c ) {
				$k = ! empty( $c['rg'] ) ? $c['rg'] : ( $c['c'] ?? 'Other' );
				if ( ! empty( $c['site'] ) ) {
					$k = $c['site'] . ': ' . $k;
				}
				if ( ! isset( $by[ $k ] ) ) {
					$by[ $k ] = array( 'area' => $k, 'clubs' => 0, 'counties' => array() );
				}
				$by[ $k ]['clubs']++;
				if ( ! empty( $c['c'] ) && ! in_array( $c['c'], $by[ $k ]['counties'], true ) ) {
					$by[ $k ]['counties'][] = $c['c'];
				}
			}
			ksort( $by );
			pn_feed_log( 'call', 'list-areas', array(), count( $by ) );
			return pn_feed_env( array( 'total_listed_clubs' => count( $all['clubs'] ), 'directory_last_reviewed' => $all['reviewed'], 'areas' => array_values( $by ) ) );
		},
		'permission_callback' => $public,
		'meta'                => $ro,
	) );

	wp_register_ability( 'pitch/search-guides', array(
		'label'               => "Search guides and articles ($site)",
		'description'         => "Search published $site guides, articles and pages: tryouts, leagues, tournaments, camps, costs, gear, standings recaps and club spotlights.",
		'category'            => 'pitch-content',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 200, 'description' => 'Words to search for in published guides and articles, such as "tryouts", "Surf Cup" or "club soccer cost".' ),
				'limit' => $limit,
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( $input ) {
			$res = pn_feed_query( array( 's' => sanitize_text_field( $input['query'] ), 'post_type' => array( 'post', 'page', 'socalp_club' ) ), pn_feed_limit( $input ) );
			pn_feed_log( 'call', 'search-guides', $input, count( $res ) );
			return pn_feed_env( array(
				'query'   => $input['query'],
				'results' => $res,
			) );
		},
		'permission_callback' => $public,
		'meta'                => $ro,
	) );

	wp_register_ability( 'pitch/get-page', array(
		'label'               => "Read a page ($site)",
		'description'         => "Full text of one published $site page or article, as plain text with headings. Pass the URL or slug. Long pages come back in parts: pass next_offset as offset to continue.",
		'category'            => 'pitch-content',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'url_or_slug' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 300, 'description' => 'The full page URL from a search result, or just its slug, such as "tryouts".' ),
				'offset'      => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Where to resume a long page. Pass the next_offset value from the previous response. Default 0.' ),
			),
			'required'             => array( 'url_or_slug' ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( $input ) {
			$ref  = trim( $input['url_or_slug'] );
			$post = null;
			if ( false !== strpos( $ref, '/' ) ) {
				$id   = url_to_postid( remove_query_arg( array( 'utm_source', 'utm_medium', 'utm_campaign' ), strtok( $ref, '#' ) ) );
				$post = $id ? get_post( $id ) : null;
			} else {
				$found = get_posts( array( 'name' => sanitize_title( $ref ), 'post_type' => array( 'post', 'page', 'socalp_club' ), 'post_status' => 'publish', 'numberposts' => 1 ) );
				$post  = $found ? $found[0] : null;
			}
			if ( ! $post || ! pn_feed_is_public( $post ) ) {
				pn_feed_log( 'call', 'get-page', $input, 0 );
				return new WP_Error( 'pitch_not_found', 'No published page on this site matches that URL or slug. Try pitch/search-guides.' );
			}
			pn_feed_log( 'call', 'get-page', $input, 1 );
			$text   = pn_feed_text( $post );
			$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
			$size   = 15000;
			$data   = pn_feed_summary( $post );
			$data['text']        = mb_substr( $text, $offset, $size );
			$data['next_offset'] = ( $offset + $size < mb_strlen( $text ) ) ? $offset + $size : null;
			return pn_feed_env( $data );
		},
		'permission_callback' => $public,
		'meta'                => $ro,
	) );

	wp_register_ability( 'pitch/whats-new', array(
		'label'               => "What's new ($site)",
		'description'         => "Articles and pages on $site published since a date (default: last 30 days), newest first: standings recaps, club spotlights, tryout and tournament updates.",
		'category'            => 'pitch-content',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'since' => array( 'type' => 'string', 'description' => 'Only items published on or after this date, as YYYY-MM-DD. Default: the last 30 days.' ),
				'limit' => $limit,
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( $input ) {
			$input = is_array( $input ) ? $input : array();
			$since = ! empty( $input['since'] ) ? strtotime( $input['since'] ) : false;
			$since = $since ?: time() - 30 * DAY_IN_SECONDS;
			pn_feed_log( 'call', 'whats-new', $input, 1 );
			return pn_feed_env( array(
				'since'   => gmdate( 'Y-m-d', $since ),
				'results' => pn_feed_query( array(
					'post_type'  => array( 'post', 'page' ),
					'orderby'    => 'date',
					'order'      => 'DESC',
					'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $since ) ) ),
				), pn_feed_limit( $input ) ),
			) );
		},
		'permission_callback' => $public,
		'meta'                => $ro,
	) );

	wp_register_ability( 'pitch/about', array(
		'label'               => $about,
		'description'         => "What $site covers, the other Pitch Network regional sites, and how to cite it.",
		'category'            => 'pitch-content',
		'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'execute_callback'    => function () {
			$cfg = pn_feed_site();
			pn_feed_log( 'call', 'about', array(), 1 );
			return pn_feed_env( array(
				'site'        => $cfg['name'],
				'tagline'     => html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ),
				'covers'      => $cfg['area'],
				'url'         => pn_feed_url( home_url( '/' ) ),
				'network'     => array(
					'hub'   => 'https://pitchnetwork.org/',
					'sites' => array(
						'SoCal Pitch'         => 'https://socalpitch.com/',
						'NorCal Pitch'        => 'https://norcalpitch.com/',
						'PNW Pitch'           => 'https://pnwpitch.com/',
						'Mountain West Pitch' => 'https://mountainwestpitch.com/',
						'Southwest Pitch'     => 'https://southwestpitch.com/',
					),
				),
				'how_to_cite' => '"<page title>," ' . $cfg['name'] . ', <page URL>.',
			) );
		},
		'permission_callback' => $public,
		'meta'                => $ro,
	) );

	wp_register_ability( 'pitch/list-sites', array(
		'label'               => 'List Pitch Network sites and their agent servers',
		'description'         => 'The regional Pitch Network sites, the areas each covers, and each site\'s own MCP server URL for guides, tryouts, tournaments and standings recaps in that region.',
		'category'            => 'pitch-content',
		'input_schema'        => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'execute_callback'    => function () {
			pn_feed_log( 'call', 'list-sites', array(), 5 );
			$covers = array(
				'SoCal Pitch'         => 'Southern California (10 counties)',
				'NorCal Pitch'        => 'Northern California',
				'PNW Pitch'           => 'Washington, Oregon, Idaho and western Montana',
				'Mountain West Pitch' => 'Colorado, Utah, Wyoming, northeast Nevada and south-central Montana',
				'Southwest Pitch'     => 'Arizona, New Mexico, southern Nevada and El Paso',
			);
			$out = array();
			foreach ( pn_feed_network_sites() as $site ) {
				$out[] = array( 'site' => $site['name'], 'covers' => $covers[ $site['name'] ], 'url' => pn_feed_url( $site['url'] . '/' ), 'mcp_server' => $site['url'] . '/wp-json/pitch/mcp' );
			}
			return pn_feed_env( array( 'sites' => $out, 'connect_page' => 'https://pitchnetwork.org/connect-your-ai/' ) );
		},
		'permission_callback' => $public,
		'meta'                => $ro,
	) );
} );

/* ---------- server ---------- */

function pn_feed_transport_permission( $request = null ) {
	/* The adapter keeps MCP sessions per user, so anonymous agents run as one account with NO role and NO capabilities, set only inside this route. */
	if ( ! is_user_logged_in() ) {
		$agent = get_user_by( 'login', 'pitch-agent-feed' );
		if ( ! $agent || ! empty( $agent->roles ) || ! empty( $agent->allcaps ) ) {
			return false;
		}
		wp_set_current_user( $agent->ID );
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'pn_mcp_rl_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 300 ) {
		return false;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	if ( $request instanceof WP_REST_Request ) {
		$body = json_decode( $request->get_body(), true );
		static $session_logged = false;
		if ( ! $session_logged && is_array( $body ) && 'initialize' === ( $body['method'] ?? '' ) ) {
			$session_logged = true;
			$ci = $body['params']['clientInfo'] ?? array();
			pn_feed_log( 'session', '', array(), 0, trim( ( $ci['name'] ?? 'unknown' ) . ' ' . ( $ci['version'] ?? '' ) ) );
		}
	}
	return true;
}

add_action( 'mcp_adapter_init', function ( $adapter ) {
	$cfg = pn_feed_site();
	$adapter->create_server(
		'pitch-agent-feed',
		'pitch',
		'mcp',
		$cfg['name'],
		'Read-only feed of ' . $cfg['name'] . ', part of The Pitch Network: the youth soccer club directory for ' . $cfg['area'] . ' plus guides on tryouts, leagues, tournaments and camps. Every answer links back to the site.',
		PN_FEED_VERSION,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		'network' === $cfg['source']
			? array( 'pitch/find-clubs', 'pitch/list-areas', 'pitch/list-sites', 'pitch/about' )
			: array( 'pitch/find-clubs', 'pitch/list-areas', 'pitch/search-guides', 'pitch/get-page', 'pitch/whats-new', 'pitch/about' ),
		array(),
		array(),
		'pn_feed_transport_permission'
	);
} );
