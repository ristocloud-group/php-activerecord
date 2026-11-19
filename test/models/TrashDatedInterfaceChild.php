<?php

use ActiveRecord\SoftDeletable;

// SoftDeletable here, the trait on its parent
class TrashDatedInterfaceChild extends TrashDatedTraitBase implements SoftDeletable {}
