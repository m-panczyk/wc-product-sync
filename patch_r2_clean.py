#!/usr/bin/env python3
"""Clean: restore from HEAD, then apply all 4 Round 2 fixes."""
import subprocess, sys

subprocess.run(
    "git checkout HEAD -- wc-product-sync.php",
    shell=True, cwd="/opt/data/workspaces/wpwc-prod-sync"
)

path = "/opt/data/workspaces/wpwc-prod-sync/wc-product-sync.php"
with open(path) as f:
    lines = f.readlines()

changes = []

# === Change 4 (UI field): find <tr> before wps_pp row, insert new <tr> after it ===
for i in range(len(lines)-1, -1, -1):
    if "<label for=\"wps_pp\">" in lines[i]:
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
        changes.append(4); break

# === Change 3 (sanitization): after consumer_secret sanitize line, insert block ===
for i in range(len(lines)-1, -1, -1):
    if "sanitize_text_field( trim( $input['consumer_secret'] ) )" in lines[i]:
        insert_lines = [
            "\n",
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
        ]
        pos = i + 1
        for k, line in enumerate(insert_lines):
            lines.insert(pos + k, line)
        changes.append(3); break

# === Change 2 (helper method): before "Ustawienia" comment block ===
for i in range(len(lines)-1, -1, -1):
    if "Ustawienia" in lines[i]:
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
        ]
        lines[i:i] = helper
        changes.append(2); break

# === Change 1 (whitelist check): after $host line inside is_private_host() ===
for i in range(len(lines)-1, -1, -1):
    if "private function is_private_host( $host )" in lines[i]:
        insert_lines = [
            "\t\t// Check explicit whitelist first.\n",
            "\t\tif ( $this->is_insecure_host_allowed( $host ) ) {\n",
            "\t\t\treturn false;\n",
            "\t\t}\n",
        ]
        pos = i + 2
        for k, line in enumerate(insert_lines):
            lines.insert(pos + k, line)
        changes.append(1); break

with open(path, 'w') as f:
    f.writelines(lines)

if len(changes) == 4:
    print(f"OK {len(changes)} changes applied: {sorted(changes)}")
else:
    print(f"FAIL: only {len(changes)}/4 applied: {changes}", file=sys.stderr)
    sys.exit(1)
