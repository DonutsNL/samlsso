#!/usr/bin/env python3
import os
import sys
import re
import subprocess
import urllib.request
import urllib.parse
import json
import time

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
    url = f"https://translate.googleapis.com/translate_a/single?client=gtx&sl={source_lang}&tl={target_lang}&dt=t&q={urllib.parse.quote(protected)}"
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    try:
        with urllib.request.urlopen(req) as response:
            data = json.loads(response.read().decode('utf-8'))
            translated = "".join([part[0] for part in data[0] if part[0]])
            restored = restore_placeholders(translated, placeholders, term_placeholders)
            return leading_icon + restored
    except Exception as e:
        print(f"Error calling translation API for language '{target_lang}': {e}", flush=True)
        return None

def parse_po(file_path):
    entries = []
    current_entry = {
        'comments': [],
        'msgid': [],
        'msgstr': []
    }
    state = None
    
    with open(file_path, 'r', encoding='utf-8') as f:
        for line in f:
            line_str = line.strip()
            if line.startswith('#'):
                if state:
                    entries.append(current_entry)
                    current_entry = {'comments': [], 'msgid': [], 'msgstr': []}
                    state = None
                current_entry['comments'].append(line.rstrip('\r\n'))
            elif line.startswith('msgid'):
                if state == 'msgstr':
                    entries.append(current_entry)
                    current_entry = {'comments': [], 'msgid': [], 'msgstr': []}
                state = 'msgid'
                match = re.match(r'^msgid\s+"(.*)"$', line.rstrip('\r\n'))
                if match:
                    current_entry['msgid'].append(match.group(1))
                else:
                    current_entry['msgid'].append("")
            elif line.startswith('msgstr'):
                state = 'msgstr'
                match = re.match(r'^msgstr\s+"(.*)"$', line.rstrip('\r\n'))
                if match:
                    current_entry['msgstr'].append(match.group(1))
                else:
                    current_entry['msgstr'].append("")
            elif line.startswith('"'):
                match = re.match(r'^"(.*)"$', line.rstrip('\r\n'))
                if match:
                    val = match.group(1)
                    if state == 'msgid':
                        current_entry['msgid'].append(val)
                    elif state == 'msgstr':
                        current_entry['msgstr'].append(val)
            elif not line_str:
                if state:
                    entries.append(current_entry)
                    current_entry = {'comments': [], 'msgid': [], 'msgstr': []}
                    state = None
        if state or current_entry['comments']:
            entries.append(current_entry)
            
    return entries

def unescape_str(s):
    return s.replace('\\n', '\n').replace('\\t', '\t').replace('\\"', '"').replace('\\\\', '\\')

def escape_str(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')

def write_po(file_path, entries):
    with open(file_path, 'w', encoding='utf-8') as f:
        for i, entry in enumerate(entries):
            for comment in entry['comments']:
                f.write(comment + '\n')
            
            if entry['msgid'] or entry['msgstr']:
                if len(entry['msgid']) == 1:
                    f.write(f'msgid "{entry["msgid"][0]}"\n')
                elif len(entry['msgid']) > 1:
                    f.write('msgid ""\n')
                    for part in entry['msgid']:
                        f.write(f'"{part}"\n')
                else:
                    f.write('msgid ""\n')
                    
                if len(entry['msgstr']) == 1:
                    f.write(f'msgstr "{entry["msgstr"][0]}"\n')
                elif len(entry['msgstr']) > 1:
                    f.write('msgstr ""\n')
                    for part in entry['msgstr']:
                        f.write(f'"{part}"\n')
                else:
                    f.write('msgstr ""\n')
                
            if i < len(entries) - 1:
                f.write('\n')

def run_command(args, description):
    print(f"Running: {description}...", flush=True)
    res = subprocess.run(args, capture_output=True, text=True)
    if res.returncode != 0:
        print(f"Error during {description}: {res.stderr}", flush=True)
        sys.exit(res.returncode)
    print(f"Success: {description}.", flush=True)

def main():
    plugin_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '../..'))
    os.chdir(plugin_dir)
    
    # 1. Load configuration
    languages = load_languages_config()
    overrides = load_overrides_config()
    print(f"Loaded target languages: {languages}", flush=True)
    
    # 2. Regenerate POT file
    run_command(['./tools/generatePot.sh'], "Generating POT file (samlSSO.pot)")
    
    locales_dir = 'locales'
    pot_file = os.path.join(locales_dir, 'samlSSO.pot')
    
    # 3. Process each configured language
    for locale in languages:
        po_file = f"{locale}.po"
        po_path = os.path.join(locales_dir, po_file)
        target_lang = locale.split('_')[0]
        
        # Initialize or merge PO file
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
            
        # Clean up gettext backup file if created
        backup_path = po_path + '~'
        if os.path.exists(backup_path):
            os.remove(backup_path)
            
        # Translate entries
        print(f"Translating {po_file} (target language: '{target_lang}')...", flush=True)
        entries = parse_po(po_path)
        
        count = 0
        for entry in entries:
            msgid_joined = "".join(entry['msgid'])
            msgstr_joined = "".join(entry['msgstr'])
            
            is_fuzzy = any(re.match(r'^#,\s*fuzzy', comment.strip()) for comment in entry['comments'])
            starts_with_icon = any(msgid_joined.startswith(icon) for icon in ['⚠️', '🔒', '⭕', '🆗', '🤔'])
            
            if msgid_joined and (not msgstr_joined or is_fuzzy or starts_with_icon):
                raw_text = unescape_str(msgid_joined)
                
                # Check for overrides first
                override_text = None
                for lang_key in [locale, target_lang]:
                    if lang_key in overrides and raw_text in overrides[lang_key]:
                        override_text = overrides[lang_key][raw_text]
                        break
                
                if override_text is not None:
                    translated_raw = override_text
                    print(f"[{locale}] Override used: \"{raw_text.strip()}\" -> \"{translated_raw.strip()}\"", flush=True)
                else:
                    if target_lang == 'en':
                        translated_raw = raw_text
                    else:
                        # If it's already translated (but was fuzzy), keep it or re-translate it if empty
                        if msgstr_joined and not starts_with_icon:
                            translated_raw = unescape_str(msgstr_joined)
                        else:
                            translated_raw = translate_api(raw_text, target_lang)
                
                if translated_raw:
                    escaped = escape_str(translated_raw)
                    if is_fuzzy:
                        new_comments = []
                        for comment in entry['comments']:
                            if re.match(r'^#,\s*fuzzy', comment.strip()):
                                cleaned = re.sub(r'fuzzy,?\s*', '', comment).strip()
                                if cleaned and cleaned != '#,' and cleaned != '#':
                                    new_comments.append(cleaned)
                            else:
                                new_comments.append(comment)
                        entry['comments'] = new_comments
                    
                    if [escaped] != entry['msgstr'] or is_fuzzy:
                        entry['msgstr'] = [escaped]
                        count += 1
                        print(f"[{locale}] [{count}] Translated/Unfuzzied: \"{raw_text.strip()}\" -> \"{translated_raw.strip()}\"", flush=True)
                        if target_lang != 'en' and not msgstr_joined:
                            time.sleep(0.05)
                        
        # Fix trailing newline mismatches for all entries
        for entry in entries:
            msgid_joined = "".join(entry['msgid'])
            msgstr_joined = "".join(entry['msgstr'])
            if msgid_joined != "":
                if msgid_joined.endswith('\\n') and not msgstr_joined.endswith('\\n'):
                    if entry['msgstr']:
                        entry['msgstr'][-1] = entry['msgstr'][-1] + '\\n'
                    else:
                        entry['msgstr'] = ['\\n']
                elif not msgid_joined.endswith('\\n') and msgstr_joined.endswith('\\n'):
                    if entry['msgstr'] and entry['msgstr'][-1].endswith('\\n'):
                        entry['msgstr'][-1] = entry['msgstr'][-1][:-2]

        print(f"[{locale}] Finished translation. Updated {count} entries.", flush=True)
        write_po(po_path, entries)


        # 4. Compile PO file to MO file
        mo_file = f"{locale}.mo"
        mo_path = os.path.join(locales_dir, mo_file)
        run_command(['msgfmt', po_path, '-o', mo_path], f"Compiling {po_file} to {mo_file}")
        
    print("All translation and compilation processes completed successfully!", flush=True)

if __name__ == '__main__':
    main()
