#!/usr/bin/env python3
import http.client
import os
import sys
import re
import subprocess
import urllib.parse
import json
import time

from po_utils import normalize_entry_newlines, parse_po, write_po

LANGUAGES_CONFIG_FILE = os.path.abspath(os.path.join(os.path.dirname(__file__), 'languages.json'))
OVERRIDES_CONFIG_FILE = os.path.abspath(os.path.join(os.path.dirname(__file__), 'overrides.json'))

def load_overrides_config():
    if os.path.exists(OVERRIDES_CONFIG_FILE):
        try:
            with open(OVERRIDES_CONFIG_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            print(f"Warning: Error loading overrides.json: {e}", flush=True)
    return {}

def load_languages_config():
    if os.path.exists(LANGUAGES_CONFIG_FILE):
        try:
            with open(LANGUAGES_CONFIG_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            print(f"Error loading languages.json: {e}", flush=True)
            sys.exit(1)
    print("languages.json not found in the tools/translation directory!", flush=True)
    sys.exit(1)

def extract_leading_icon(text):
    # Match leading icons like ⚠️, 🔒, ⭕, 🆗, 🤔 plus optional spaces
    match = re.match(r'^([⚠️🔒⭕🆗🤔]\s*)(.*)$', text)
    if match:
        return match.group(1), match.group(2)
    return "", text

PROTECTED_TERMS = [
    'proxy', 'proxied', 'saml', 'idp', 'sp', 'glpi', 'metadata',
    'acs', 'slo', 'xml', 'x509', 'x.509', 'base64', 'url', 'uri', 'jwt',
    'tls', 'ssl', 'oauth', 'php', 'mysql', 'active directory',
    'entra id', 'entra', 'okta', 'keycloak', 'sso', 'shibboleth',
    'onelogin', 'ldap', 'adfs', 'https', 'http', 'fqdn', 'jit', 'host',
    'apache', 'nginx', 'iis', 'traefik', 'cookie', 'cookies', 'token',
    'tokens', 'entity id', 'entityid', 'claim', 'claims'
]

def protect_placeholders(text):
    # 1. Extract and protect format placeholders, tags, etc.
    pattern = r'(%[0-9]*\$?[a-zA-Z]|{[a-zA-Z0-9_]+}|<[^>]+>)'
    placeholders = re.findall(pattern, text)
    temp_text = text
    for i, ph in enumerate(placeholders):
        temp_text = temp_text.replace(ph, f" PH{i}PH ", 1)

    # 2. Extract and protect common IT terms
    sorted_terms = sorted(PROTECTED_TERMS, key=len, reverse=True)
    term_placeholders = []
    for term in sorted_terms:
        # Match whole word boundaries case-insensitively
        pattern_term = r'\b' + re.escape(term) + r'\b'
        
        def replace_match(match):
            original_val = match.group(0)
            placeholder = f" TERM{len(term_placeholders)}TERM "
            term_placeholders.append(original_val)
            return placeholder
            
        temp_text = re.sub(pattern_term, replace_match, temp_text, flags=re.IGNORECASE)
        
    return temp_text, placeholders, term_placeholders

def restore_placeholders(translated_text, placeholders, term_placeholders):
    temp_text = translated_text
    
    # 1. Restore IT terms
    for i, original_val in enumerate(term_placeholders):
        pattern = r'\s*TERM' + str(i) + r'TERM\s*'
        temp_text = re.sub(pattern, f" {original_val} ", temp_text, count=1)

    # 2. Restore format placeholders
    for i, ph in enumerate(placeholders):
        pattern = r'\s*PH' + str(i) + r'PH\s*'
        temp_text = re.sub(pattern, f" {ph} ", temp_text, count=1)
        
    # Clean up duplicate/extra spaces
    temp_text = re.sub(r'\s+', ' ', temp_text).strip()
    return temp_text

def translate_api(text, target_lang, source_lang="en"):
    if not text.strip():
        return text
    
    leading_icon, text_to_translate = extract_leading_icon(text)
    if not text_to_translate.strip():
        return text
        
    protected, placeholders, term_placeholders = protect_placeholders(text_to_translate)
    query_path = f"/translate_a/single?client=gtx&sl={source_lang}&tl={target_lang}&dt=t&q={urllib.parse.quote(protected)}"
    conn = None
    try:
        conn = http.client.HTTPSConnection('translate.googleapis.com', timeout=15)
        conn.request('GET', query_path, headers={'User-Agent': 'Mozilla/5.0'})
        response = conn.getresponse()
        if response.status != 200:
            raise RuntimeError(f"HTTP {response.status} {response.reason}")
        data = json.loads(response.read().decode('utf-8'))
        translated = "".join([part[0] for part in data[0] if part[0]])
        restored = restore_placeholders(translated, placeholders, term_placeholders)
        return leading_icon + restored
    except Exception as e:
        print(f"Error calling translation API for language '{target_lang}': {e}", flush=True)
        return None
    finally:
        if conn is not None:
            conn.close()


def unescape_str(s):
    return s.replace('\\n', '\n').replace('\\t', '\t').replace('\\"', '"').replace('\\\\', '\\')


def escape_str(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')


def run_command(args, description):
    print(f"Running: {description}...", flush=True)
    res = subprocess.run(args, capture_output=True, text=True)
    if res.returncode != 0:
        print(f"Error during {description}: {res.stderr}", flush=True)
        sys.exit(res.returncode)
    print(f"Success: {description}.", flush=True)


def _sync_po_with_pot(pot_file, po_path, po_file, locale):
    if not os.path.exists(po_path):
        run_command(
            ['msginit', '-i', pot_file, '-o', po_path, '-l', locale, '--no-translator'],
            f"Initializing empty PO file for {locale}"
        )
    else:
        run_command(
            ['msgmerge', '--update', po_path, pot_file],
            f"Merging POT into {po_file}"
        )

    backup_path = po_path + '~'
    if os.path.exists(backup_path):
        os.remove(backup_path)


def _strip_fuzzy_flag(comments):
    new_comments = []
    for comment in comments:
        if re.match(r'^#,\s*fuzzy', comment.strip()):
            cleaned = re.sub(r'fuzzy,?\s*', '', comment).strip()
            if cleaned and cleaned not in ('#,', '#'):
                new_comments.append(cleaned)
        else:
            new_comments.append(comment)
    return new_comments


def _resolve_entry_translation(raw_text, msgstr_joined, starts_with_icon, locale, target_lang, overrides):
    for lang_key in (locale, target_lang):
        if lang_key in overrides and raw_text in overrides[lang_key]:
            translated_raw = overrides[lang_key][raw_text]
            print(f"[{locale}] Override used: \"{raw_text.strip()}\" -> \"{translated_raw.strip()}\"", flush=True)
            return translated_raw

    if target_lang == 'en':
        return raw_text

    if msgstr_joined and not starts_with_icon:
        return unescape_str(msgstr_joined)

    return translate_api(raw_text, target_lang)


def _process_locale(locale, locales_dir, pot_file, overrides):
    po_file = f"{locale}.po"
    po_path = os.path.join(locales_dir, po_file)
    target_lang = locale.split('_')[0]

    _sync_po_with_pot(pot_file, po_path, po_file, locale)

    print(f"Translating {po_file} (target language: '{target_lang}')...", flush=True)
    entries = parse_po(po_path)
    icon_prefixes = ('⚠️', '🔒', '⭕', '🆗', '🤔')

    count = 0
    for entry in entries:
        msgid_joined = "".join(entry['msgid'])
        msgstr_joined = "".join(entry['msgstr'])
        is_fuzzy = any(re.match(r'^#,\s*fuzzy', c.strip()) for c in entry['comments'])
        starts_with_icon = any(msgid_joined.startswith(icon) for icon in icon_prefixes)

        if not msgid_joined or (msgstr_joined and not is_fuzzy and not starts_with_icon):
            normalize_entry_newlines(entry)
            continue

        raw_text = unescape_str(msgid_joined)
        translated_raw = _resolve_entry_translation(
            raw_text, msgstr_joined, starts_with_icon, locale, target_lang, overrides
        )
        if translated_raw:
            escaped = escape_str(translated_raw)
            if is_fuzzy:
                entry['comments'] = _strip_fuzzy_flag(entry['comments'])
            if [escaped] != entry['msgstr'] or is_fuzzy:
                entry['msgstr'] = [escaped]
                count += 1
                print(f"[{locale}] [{count}] Translated/Unfuzzied: \"{raw_text.strip()}\" -> \"{translated_raw.strip()}\"", flush=True)
                if target_lang != 'en' and not msgstr_joined:
                    time.sleep(0.05)

        normalize_entry_newlines(entry)

    print(f"[{locale}] Finished translation. Updated {count} entries.", flush=True)
    write_po(po_path, entries)

    mo_file = f"{locale}.mo"
    mo_path = os.path.join(locales_dir, mo_file)
    run_command(['msgfmt', po_path, '-o', mo_path], f"Compiling {po_file} to {mo_file}")


def main():
    plugin_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '../..'))
    os.chdir(plugin_dir)

    languages = load_languages_config()
    overrides = load_overrides_config()
    print(f"Loaded target languages: {languages}", flush=True)

    run_command(['./tools/generatePot.sh'], "Generating POT file (samlSSO.pot)")

    locales_dir = 'locales'
    pot_file = os.path.join(locales_dir, 'samlSSO.pot')
    for locale in languages:
        _process_locale(locale, locales_dir, pot_file, overrides)

    print("All translation and compilation processes completed successfully!", flush=True)

if __name__ == '__main__':
    main()
