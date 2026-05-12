const fs = require('fs');
const path = require('path');

const pluginDir = 'c:\\Users\\dataadmin546\\Desktop\\PROJECTS\\Antigravity\\PowerCreativesv2\\app\\public\\wp-content\\plugins\\power-creatives';
const tmpFile = path.join('c:\\Users\\dataadmin546\\.gemini\\antigravity\\brain\\c3154d45-bd96-487c-91e6-21df3762e778', 'all_files.tmp');
const auditFile = path.join(pluginDir, 'docs', 'audits', '20260404-audit-file.md');
const auditCode = path.join(pluginDir, 'docs', 'audits', '20260404-audit-code.md');

const allFiles = fs.readFileSync(tmpFile, 'utf8').split('\n').map(l => l.trim()).filter(Boolean);

const filteredFiles = allFiles.filter(f => {
    if (f.includes('node_modules')) return false;
    if (f.includes('.git/')) return false;
    if (f.includes('build/')) return false;
    if (f.includes('vendor/')) return false;
    if (f.endsWith('.lock')) return false;
    if (f.endsWith('package-lock.json')) return false;
    if (f.endsWith('.woff2')) return false;
    if (f.endsWith('.tmp')) return false;
    return true;
});

// Create 20260404-audit-file.md
let auditFileContent = `# File Audit - Complete File List\n\n`;
filteredFiles.forEach((f, i) => {
    auditFileContent += `[ ] ${i + 1}. ${f}\n`;
});
auditFileContent += `\n## Documentation\n`;

fs.writeFileSync(auditFile, auditFileContent, 'utf8');

// Create 20260404-audit-code.md
let auditCodeContent = `# File Audit - Code Checklist\n\n## Checklist\n\n`;
filteredFiles.forEach((f, i) => {
    auditCodeContent += `[ ] ${i + 1}. ${f}\n`;
    auditCodeContent += `  [ ] Step 1: LÄS\n`;
    auditCodeContent += `  [ ] Step 2: INVENTERA\n`;
});

auditCodeContent += `\n## File Reviews\n\n`;
fs.writeFileSync(auditCode, auditCodeContent, 'utf8');

console.log('Successfully generated checklists for ' + filteredFiles.length + ' files.');
