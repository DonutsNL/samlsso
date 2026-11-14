import re


def _empty_entry():
    return {'comments': [], 'msgid': [], 'msgstr': []}


def _extract_quoted_value(line_text, prefix):
    match = re.match(rf'^{prefix}\s+"(.*)"$', line_text)
    return match.group(1) if match else ""


def _parse_po_line(line, entries, current_entry, state):
    stripped = line.rstrip('\r\n')
    if line.startswith('#'):
        if state:
            entries.append(current_entry)
            current_entry = _empty_entry()
            state = None
        current_entry['comments'].append(stripped)
        return current_entry, state

    if line.startswith('msgid'):
        if state == 'msgstr':
            entries.append(current_entry)
            current_entry = _empty_entry()
        current_entry['msgid'].append(_extract_quoted_value(stripped, 'msgid'))
        return current_entry, 'msgid'

    if line.startswith('msgstr'):
        current_entry['msgstr'].append(_extract_quoted_value(stripped, 'msgstr'))
        return current_entry, 'msgstr'

    if line.startswith('"') and state in ('msgid', 'msgstr'):
        match = re.match(r'^"(.*)"$', stripped)
        if match:
            current_entry[state].append(match.group(1))
        return current_entry, state

    if not line.strip() and state:
        entries.append(current_entry)
        return _empty_entry(), None

    return current_entry, state


def parse_po(file_path):
    entries = []
    current_entry = _empty_entry()
    state = None

    with open(file_path, 'r', encoding='utf-8') as f:
        for line in f:
            current_entry, state = _parse_po_line(line, entries, current_entry, state)

    if state or current_entry['comments']:
        entries.append(current_entry)

    return entries


def _write_po_field(handle, keyword, parts):
    if len(parts) == 1:
        handle.write(f'{keyword} "{parts[0]}"\n')
        return

    handle.write(f'{keyword} ""\n')
    for part in parts:
        handle.write(f'"{part}"\n')


def write_po(file_path, entries):
    with open(file_path, 'w', encoding='utf-8') as f:
        for i, entry in enumerate(entries):
            for comment in entry['comments']:
                f.write(comment + '\n')

            if entry['msgid'] or entry['msgstr']:
                _write_po_field(f, 'msgid', entry['msgid'])
                _write_po_field(f, 'msgstr', entry['msgstr'])

            if i < len(entries) - 1:
                f.write('\n')


def normalize_entry_newlines(entry):
    msgid_joined = "".join(entry['msgid'])
    msgstr_joined = "".join(entry['msgstr'])
    if msgid_joined == "":
        return

    if msgid_joined.endswith('\\n') and not msgstr_joined.endswith('\\n'):
        if entry['msgstr']:
            entry['msgstr'][-1] = entry['msgstr'][-1] + '\\n'
        else:
            entry['msgstr'] = ['\\n']
    elif not msgid_joined.endswith('\\n') and msgstr_joined.endswith('\\n'):
        if entry['msgstr'] and entry['msgstr'][-1].endswith('\\n'):
            entry['msgstr'][-1] = entry['msgstr'][-1][:-2]
