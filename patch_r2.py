#!/usr/bin/env python3
"""Apply Round 2 review fixes to wc-product-sync.php - reverse order to avoid line shifts."""

import sys
path = "/opt/data/workspaces/wpwc-prod-sync/wc-product-sync.php"

with open(path) as f:
    lines = f.readlines()

# Work in REVERSE so earlier inserts don't affect later position lookups
changes = []

# Change 4 FIRST (highest line number): UI field
for i in range(len(lines)-1, -1, -1):
    if "<label for=\"wps_pp\">" in lines[i]:
        # Find </tr> before this
        j = i - 1
        while j >= 0 and "\t\t\t\t</tr>" not in lines[j]:
            j -= 1
        ui = [
            "\t\t\t\t<tr>\n",
            '\t\t\t\t\t<th scope="row"><label for="wps_insecure"><?php esc_html_e( \'Dozwolone hosty HTTP\', \'wc-product-sync\' ); ?></label></th>\n',
            '\t\t\t\t\t<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[insecure_hosts]" id="wps_insecure" type="text" class="regular-text"\n',
            '\t\t\t\t\t\tvalue="<?php echo esc_attr( isset( $opts[\'insecure_hosts\'] ) ? $opts[\'insecure_hosts\'] : \'\' ); ?>" />\n',
            '\t\t\t\t\t<p class="description"><?php esc_html_e( \'Lista hostów rozdzielona przecinkami, które mogą używać HTTP (bez http:// ani portu). Domyślnie wszystkie bez kropki w nazwie — np. serwisy Docker — są blokowane; wpisz tu swoje (np. src-wp) aby zezwolić.\', \'wc-product-sync\' ); ?></p></td>\n',
            "\t\t\t\t</tr>\n",
        ]
        lines[j:j] = ui
        changes.append(4)
        break

# Change 3 (middle): Sanitization
for i in range(len(lines)-1, -1, -1):
    if "sanitize_text_field( trim( $input['consumer_secret'] ) )" in lines[i]:
        lines.insert(i+1, "\n")
        for k, line in enumerate([
            "\t\t// Sanitize insecure_hosts.\n",
            "\t\tif ( isset( $input['insecure_hosts'] ) ) {\n",
            "\t\t\t$ih = sanitize_text_field( trim( $input['insecure_hosts'] ) );\n",
            "\t\t\t$valid = true;\n",
            "\t\t\tif ( '' !== $ih ) {\n",
            "\t\t\t\tforeach ( explode( ',', $ih ) as $h ) {\n",
            "\t\t\t\t\t$h = trim( $h );\n",
            "\t\t\t\t\tif ( $h && ! preg_match( '/^[a-z0-9][a-z0-9.-]*[a-z0-9]$|^[a-z0-9]$/', $h ) ) {\n",
            "\t\t\t\t\t\t$valid = false;\n",
            "\t\t\t\t\t\tbreak;\n",
            "\t\t\t\t\t}\n",
            "\t\t\t\t}\n",
            "\t\t\t}\n",
            "\t\t\t$out['insecure_hosts'] = $valid ? $ih : ( isset( $out['insecure_hosts'] ) ? $out['insecure_hosts'] : '' );\n",
        ]):
            lines.insert(i+2+k, line)
        changes.append(3)
        break

# Change 1 (lowest line number): Whitelist in is_private_host()
for i in range(len(lines)-1, -1, -1):
    if "private function is_private_host( $host )" in lines[i]:
        insert_at = i + 2
        for k, line in enumerate([
            "\t\t// Check explicit whitelist first.\n",
            "\t\tif ( $this->is_insecure_host_allowed( $host ) ) {\n",
            "\t\t\treturn false;\n",
            "\t\t}\n",
        ]):
            lines.insert(insert_at+k, line)
        changes.append(1)
        break

# Change 2: Helper method before Settings API
for i in range(len(lines)-1, -1, -1):
    if "Ustawienia (Settings API)" in lines[i] and (i > 0 and "/*" in lines[i-1]):
        helper = [
            "\t/** Check if a host is in the 'insecure_hosts' whitelist. Comma-separated list of\n",
            "\t *  lowercase hostnames (no scheme, no port). Each entry is trimmed before matching.\n",
            "\t *  Single-character names like 'redis' are allowed. */\n",
            "\tprivate function is_insecure_host_allowed( $host ) {\n",
            "\t\t$raw = isset( $this->get_options()['insecure_hosts'] ) ? $this->get_options()['insecure_hosts'] : '';\n",
            "\t\tif ( ! is_string( $raw ) || '' === trim( $raw ) ) {\n",
            "\t\t\treturn false;\n",
            "\t\t}\n",
            "\t\t$hosts = array_map( 'strtolower', array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );\n",
            "\t\treturn in_array( strtolower( $host ), $hosts, true );\n",
            "\t}\n",
            "\n",
        ]
        lines[i:i] = helper
        changes.append(2)
        break

with open(path, 'w') as f:
    f.writelines(lines)

if len(changes) == 4:
    print(f"OK {len(changes)} changes applied: {sorted(changes)}")
else:
    print(f"FAIL: only {len(changes)}/4 applied: {changes}", file=sys.stderr)
    sys.exit(1)
