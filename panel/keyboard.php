<?php
// The old drag-and-drop page loaded its data from api/keyboard.php, which only
// takes the API token, so it never opened; its save replaced the whole of
// setting.keyboardmain - the Persian menu and every message's 🎨 sticker and
// reaction kept beside it - and «reset» did the same from a plain link. The
// main menu is arranged per language on menu.php, through the bot's own
// helpers.
require_once __DIR__ . '/inc/config.php';
require_auth();
header('Location: menu.php');
exit;
