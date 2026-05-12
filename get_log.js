const { execSync } = require('child_process');
const fs = require('fs');
try {
  const result = execSync('git log --oneline --format="%h | %ad | %s" --date=format:"%Y-%m-%d %H:%M" -n 25');
  fs.writeFileSync('git_history_utf8.txt', result, 'utf8');
} catch (e) {
  fs.writeFileSync('git_history_utf8.txt', e.toString(), 'utf8');
}
