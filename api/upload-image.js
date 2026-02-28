// Simple Node.js API for image upload to assets/images
const path = require('path');
const fs = require('fs');

const uploadDir = path.join(__dirname, '../assets/images');
if (!fs.existsSync(uploadDir)) fs.mkdirSync(uploadDir, { recursive: true });

module.exports = (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') { res.status(200).end(); return; }

  if (req.method !== 'POST') {
    res.status(405).json({ error: 'Kun POST tilladt' });
    return;
  }

  let body = '';
  req.on('data', chunk => { body += chunk.toString(); });
  req.on('end', () => {
    try {
      const { filename, dataUrl } = JSON.parse(body);
      if (!filename || !dataUrl) {
        res.status(400).json({ error: 'Mangler filename eller dataUrl' });
        return;
      }
      // Extract base64 data
      const matches = dataUrl.match(/^data:(image\/(?:jpeg|png));base64,(.+)$/);
      if (!matches) {
        res.status(400).json({ error: 'Ugyldig dataUrl format' });
        return;
      }
      const ext = matches[1] === 'image/png' ? '.png' : '.jpg';
      const safeName = filename.replace(/[^a-zA-Z0-9_-]/g, '_') + '-' + Date.now() + ext;
      const filePath = path.join(uploadDir, safeName);
      fs.writeFileSync(filePath, Buffer.from(matches[2], 'base64'));
      res.status(200).json({ url: 'assets/images/' + safeName });
    } catch (e) {
      res.status(500).json({ error: 'Upload-fejl', details: e.message });
    }
  });
};
