<?php

/**
 * Runs on the default (SQLite) connection. rooms.id is INT PRIMARY KEY, a
 * hand-assigned key: only a single pk column declared exactly INTEGER is
 * SQLite's rowid alias, so this one is never generated and the model keeps
 * the id it is created with.
 *
 * @property int         $id
 * @property string|null $name
 */
class Room extends ActiveRecord\Model {}
