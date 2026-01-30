<?php
function requireLogin() { return true; }
function getCurrentUser() { return ['username' => 'admin', 'role' => 'admin']; }
function isAdmin() { return true; }
?>