#!/usr/bin/env python3
import os
import shutil
import zipfile
import datetime

# Root directory of the plugin source code
ROOT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROJECT_NAME = "powerplatform" # Top level folder inside ZIP

# Excluded directory and file patterns
EXCLUDE_DIRS = {
    '.git',
    'node_modules',
    '.claude',
    'scripts',
    # vendor/ is DEV-ONLY: composer 'require' has no runtime packages (only php),
    # every dep is require-dev test tooling (phpunit/mockery/wp_mock/php-parser) and
    # the plugin never loads vendor/autoload at runtime. Shipping it bloated the zip
    # ~1.9MB -> 5.1MB with test frameworks a client install must not receive.
    'vendor',
    # tests/ is DEV-ONLY too — nothing under includes/ requires it at runtime.
    # It had shipped in every zip simply because it was never listed here
    # (found 2026-08-13 when an archive check first asserted its absence).
    'tests',
}

EXCLUDE_FILES = {
    '.gitignore',
    '.phpunit.result.cache',
    '.DS_Store'
}

def should_exclude(rel_path):
    if not rel_path:
        return False
    parts = rel_path.split(os.sep)
    for part in parts:
        if part in EXCLUDE_DIRS or part in EXCLUDE_FILES or part.endswith('.zip') or part.endswith('.pyc') or part == '.DS_Store':
            return True
    # Exclude app/src
    if len(parts) >= 2 and parts[0] == 'app' and parts[1] == 'src':
        return True
    return False

def build_zip():
    today_str = datetime.date.today().isoformat()
    dest_dir = os.path.expanduser('~/Desktop/powercreatives')
    os.makedirs(dest_dir, exist_ok=True)
    
    primary_zip_path = os.path.expanduser('~/Desktop/power-creatives.zip')
    named_zip_path = os.path.join(dest_dir, 'power-creatives.zip')
    dated_zip_path = os.path.join(dest_dir, f'power-creatives-{today_str}.zip')
    landing_demo_zip = os.path.expanduser('~/Desktop/Claude code/Landing page -demo/power-creatives.zip')

    temp_zip_path = primary_zip_path + '.tmp'
    
    total_files = 0
    with zipfile.ZipFile(temp_zip_path, 'w', compression=zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(f'{PROJECT_NAME}/', '')
        
        for root, dirs, files in os.walk(ROOT_DIR):
            dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS and d != '.git' and d != 'node_modules']
            
            rel_root = os.path.relpath(root, ROOT_DIR)
            if rel_root == '.':
                rel_root = ''
            
            if should_exclude(rel_root):
                continue
                
            for f in files:
                if f in EXCLUDE_FILES or f.endswith('.zip') or f.endswith('.pyc') or f == '.DS_Store':
                    continue
                file_rel_path = os.path.join(rel_root, f) if rel_root else f
                if should_exclude(file_rel_path):
                    continue
                    
                abs_file_path = os.path.join(root, f)
                entry_path = f"{PROJECT_NAME}/" + file_rel_path.replace(os.sep, '/')
                zf.write(abs_file_path, entry_path)
                total_files += 1

    shutil.move(temp_zip_path, primary_zip_path)
    shutil.copy2(primary_zip_path, named_zip_path)
    shutil.copy2(primary_zip_path, dated_zip_path)
    if os.path.exists(os.path.dirname(landing_demo_zip)):
        shutil.copy2(primary_zip_path, landing_demo_zip)

    size_mb = os.path.getsize(primary_zip_path) / (1024 * 1024)
    print(f"Successfully built ZIP package!")
    print(f"Total files in ZIP: {total_files}")
    print(f"ZIP size: {size_mb:.2f} MB")
    print(f"Saved to:")
    print(f"  - {primary_zip_path}")
    print(f"  - {named_zip_path}")
    print(f"  - {dated_zip_path}")
    print(f"  - {landing_demo_zip}")

if __name__ == '__main__':
    build_zip()
