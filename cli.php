<?php

/** returns a trimmed line from standard input */
function getline() {
    return trim(fgets(STDIN));
}

/** sprintf to standard output */
function putline($line) {
    $args = func_get_args();
    $args[0] = $line;
    fwrite(STDOUT, call_user_func_array('sprintf', $args)); 
}
