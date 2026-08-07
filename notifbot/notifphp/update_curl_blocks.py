#!/usr/bin/env python3
import re

files_to_update = {
    "non_aktif_tempo.php": [
        386, 470, 597, 777, 820
    ],
    "notif_cek_servernotif.php": [
        350
    ],
}

base_path = "d:\\quenbytekniksejahtera.com\\remote2\\crm\\billing\\notifbot\\notifphp\\"

for filename, line_numbers in files_to_update.items():
    filepath = base_path + filename
    with open(filepath, 'r', encoding='utf-8') as f:
        lines = f.readlines()
    
    # For each line number (0-based), find the CURLOPT_USERPWD and inject bot selection before it
    replacements_made = 0
    for line_num in line_numbers:
        idx = line_num - 1  # Convert to 0-based
        if idx < len(lines) and 'CURLOPT_USERPWD' in lines[idx]:
            indent = len(lines[idx]) - len(lines[idx].lstrip())
            indent_str = ' ' * indent
            
            # Check if already updated
            if '$currentBotName' in lines[idx]:
                print(f"  Already updated at line {line_num}")
                continue
            
            # Find the curl_setopt call for URL to inject before it
            # Go backwards to find where to insert
            insert_idx = idx
            for i in range(idx-1, max(0, idx-20), -1):
                if 'curl_setopt($ch, CURLOPT_URL' in lines[i]:
                    insert_idx = i
                    break
            
            # Inject bot selection code
            bot_selection = [
                f"{indent_str}// === SELECT BOT FROM POOL FOR MULTI-BOT DISTRIBUTION ===\n",
                f"{indent_str}$currentBotName = $botname;\n",
                f"{indent_str}$currentBotPass = $botpass;\n",
                f"{indent_str}$currentWAAPI = $waapi;\n",
                f"{indent_str}\n",
                f"{indent_str}if ($isRandomBot && $botPoolCount > 0) {{\n",
                f"{indent_str}    $currentBotConfig = $botPool[$targetIndex % $botPoolCount];\n",
                f"{indent_str}    $currentBotName = $currentBotConfig['namebot'];\n",
                f"{indent_str}    $currentBotPass = $currentBotConfig['password'];\n",
                f"{indent_str}    $currentWAAPI = $currentBotConfig['addressbot'];\n",
                f"{indent_str}}}\n",
                f"{indent_str}\n",
            ]
            
            # Insert before CURLOPT_URL
            for injection in reversed(bot_selection):
                lines.insert(insert_idx, injection)
            
            # Update the CURLOPT_USERPWD line
            lines[idx + len(bot_selection)] = lines[idx + len(bot_selection)].replace(
                'curl_setopt($ch, CURLOPT_USERPWD, "$botname:$botpass");',
                'curl_setopt($ch, CURLOPT_USERPWD, "$currentBotName:$currentBotPass");'
            )
            
            # Add targetIndex increment after curl_close
            for i in range(idx + len(bot_selection), min(len(lines), idx + len(bot_selection) + 10)):
                if 'curl_close($ch);' in lines[i]:
                    increment = [
                        f"{indent_str}\n",
                        f"{indent_str}// Increment target counter untuk distribusi ke bot berikutnya\n",
                        f"{indent_str}if ($isRandomBot && $botPoolCount > 0) {{\n",
                        f"{indent_str}    $targetIndex++;\n",
                        f"{indent_str}}}\n",
                    ]
                    for inj in reversed(increment):
                        lines.insert(i + 1, inj)
                    replacements_made += 1
                    break
    
    if replacements_made > 0:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.writelines(lines)
        print(f"✓ Updated {filename}: {replacements_made} curl blocks")
    else:
        print(f"✗ {filename}: No changes needed or already updated")

print("\nDone!")
