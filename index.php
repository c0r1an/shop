<?php
session_start();
error_reporting(-1);
ini_set('display_errors','On');

define('CONFIG_DIR',__DIR__.'/config');
define('ASSETS_DIR',__DIR__.'/assets');
require __DIR__.'/includes.php';
