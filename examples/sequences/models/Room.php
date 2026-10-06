<?php

/**
 * Runs on the default (SQLite) connection. rooms.id is INT PRIMARY KEY, a
 * hand-assigned key: it is not SQLite's rowid alias (a lone INTEGER PRIMARY
 * KEY on an ordinary rowid table, not declared DESC inline), so it is never
 * generated and the model keeps the id it is created with.
 *
 * @property int         $id
 * @property string|null $name
 */
class Room extends ActiveRecord\Model {}
