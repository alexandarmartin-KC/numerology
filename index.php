<?php
// Failsafe: serve index.html if DirectoryIndex doesn't work
readfile(__DIR__ . '/index.html');
