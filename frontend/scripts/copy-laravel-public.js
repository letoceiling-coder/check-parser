/**
 * Копирует index.php и .htaccess из laravel-public в public (кроссплатформенно).
 */
const fs = require('fs');
const path = require('path');

const fromDir = path.join(__dirname, '..', 'laravel-public');
const toDir = path.join(__dirname, '..', '..', 'public');

const files = ['index.php', '.htaccess'];
for (const file of files) {
  const src = path.join(fromDir, file);
  const dest = path.join(toDir, file);
  if (fs.existsSync(src)) {
    fs.copyFileSync(src, dest);
    console.log('Copied:', file);
  }
}
