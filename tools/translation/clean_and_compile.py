import os
import subprocess

from po_utils import normalize_entry_newlines, parse_po, write_po


def clean_and_compile(po_path):
    entries = parse_po(po_path)
    cleaned_entries = []
    seen_msgids = set()
    header_seen = False

    for entry in entries:
        msgid_joined = "".join(entry['msgid'])

        if msgid_joined == "":
            if not header_seen:
                header_seen = True
                cleaned_entries.append(entry)
            continue

        if msgid_joined in seen_msgids:
            continue

        seen_msgids.add(msgid_joined)
        normalize_entry_newlines(entry)
        cleaned_entries.append(entry)
        
    write_po(po_path, cleaned_entries)
    
    # Compile
    mo_path = po_path[:-3] + '.mo'
    res = subprocess.run(['msgfmt', po_path, '-o', mo_path], capture_output=True, text=True)
    if res.returncode == 0:
        print(f"Successfully compiled {po_path} to {mo_path}", flush=True)
    else:
        print(f"Error compiling {po_path}: {res.stderr}", flush=True)

def main():
    plugin_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '../..'))
    os.chdir(plugin_dir)
    locales_dir = 'locales'
    for file in os.listdir(locales_dir):
        if file.endswith('.po') and not file.endswith('~'):
            po_path = os.path.join(locales_dir, file)
            clean_and_compile(po_path)

if __name__ == '__main__':
    main()

