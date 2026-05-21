import re, sys
with open('C:/Users/rikchodam/Herd/oleochemicalproject/resources/views/ai-providers/index.blade.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Find the test link
idx = content.find('ai-providers.test')
print('Test at:', idx)
# Print raw bytes around it
if idx > 0:
    snippet = content[idx-50:idx+180]
    for i, c in enumerate(snippet):
        if ord(c) > 127:
            print(f'  pos {i}: U+{ord(c):04X}')
    print('---')
    # Just print ASCII
    clean = ''.join(c if ord(c) < 128 else '?' for c in snippet)
    print(clean)
