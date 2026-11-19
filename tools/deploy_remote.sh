# Runs ON the server. Args: WPROOT PLUGIN_SLUG AGENT_LOGIN AGENT_EMAIL BACKUP_NAME
set -e
WPROOT="$1"; SLUG="$2"; AGENT="$3"; EMAIL="$4"; BK="$5"
W="wp --skip-plugins=grow-for-wp --path=$WPROOT"
mkdir -p ~/sterling-backups && $W db export ~/sterling-backups/$BK --quiet && chmod 600 ~/sterling-backups/$BK && echo "backup ok: $BK"
$W plugin is-installed mcp-adapter || $W plugin install mcp-adapter --quiet
mkdir -p $WPROOT/wp-content/plugins/$SLUG
mv ~/agent-feed-upload.php $WPROOT/wp-content/plugins/$SLUG/$SLUG.php
php -l $WPROOT/wp-content/plugins/$SLUG/$SLUG.php
$W user get $AGENT --field=ID >/dev/null 2>&1 || $W user create $AGENT $EMAIL --role=subscriber --display_name="Agent Feed (no role)" --porcelain >/dev/null
$W user remove-role $AGENT subscriber >/dev/null 2>&1 || true
$W eval "\$u=get_user_by('login','$AGENT'); echo 'agent id='.\$u->ID.' roles='.json_encode(\$u->roles).' caps='.json_encode(\$u->allcaps).PHP_EOL;"
$W plugin activate mcp-adapter $SLUG
$W sg purge 2>/dev/null | tail -1 || true
