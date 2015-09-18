<?php

/**
 * Returns all needed angeltypes and already taken needs.
 *
 * @param shiftID id of shift
 */
function NeededAngelTypes_by_shift($shiftId) {
  $needed_angeltypes_source = sql_select("
        SELECT `NeededAngelTypes`.*, `AngelTypes`.`name`, `AngelTypes`.`restricted`
        FROM `NeededAngelTypes`
        JOIN `AngelTypes` ON `AngelTypes`.`id` = `NeededAngelTypes`.`angel_type_id`
        WHERE `shift_id`='" . sql_escape($shiftId) . "'
        AND `count` > 0
        ORDER BY `room_id` DESC
        ");
  if ($needed_angeltypes_source === false)
    return false;

    // Use settings from room
  if (count($needed_angeltypes_source) == 0) {
    $needed_angeltypes_source = sql_select("
        SELECT `NeededAngelTypes`.*, `AngelTypes`.`name`, `AngelTypes`.`restricted`
        FROM `NeededAngelTypes`
        JOIN `AngelTypes` ON `AngelTypes`.`id` = `NeededAngelTypes`.`angel_type_id`
        JOIN `Shifts` ON `Shifts`.`RID` = `NeededAngelTypes`.`room_id`
        WHERE `Shifts`.`SID`='" . sql_escape($shiftId) . "'
        AND `count` > 0
        ORDER BY `room_id` DESC
        ");
    if ($needed_angeltypes_source === false)
      return false;
  }

  $needed_angeltypes = array();
  foreach ($needed_angeltypes_source as $angeltype) {
    $shift_entries = ShiftEntries_by_shift_and_angeltype($shiftId, $angeltype['angel_type_id']);
    if ($shift_entries === false)
      return false;

    $angeltype['taken'] = count($shift_entries);
    $needed_angeltypes[] = $angeltype;
  }

  return $needed_angeltypes;
}

/**
 * Finds a single AngleType a Room id where it is missing and counts it.
 *
 * @param $roomId
 * @return Result|array
 */
function findAndCountNeededAngelTypesByRoom($roomId)
{
    return sql_select(
        sprintf(
            "SELECT `AngelTypes`.*, `NeededAngelTypes`.`count`
            FROM `AngelTypes`
            LEFT JOIN `NeededAngelTypes`
            ON (`NeededAngelTypes`.`angel_type_id` = `AngelTypes`.`id` AND `NeededAngelTypes`.`room_id`='%s')
            ORDER BY `AngelTypes`.`name`",
            sql_escape($roomId)
        )
    );
}

function findAndCountNeededAngelTypesByShift($shiftId)
{
    return sql_select(
        sprintf(
            "SELECT `AngelTypes`.*, `NeededAngelTypes`.`count`
            FROM `AngelTypes`
            LEFT JOIN `NeededAngelTypes`
            ON (`NeededAngelTypes`.`angel_type_id` = `AngelTypes`.`id` AND `NeededAngelTypes`.`shift_id`='%s')
            ORDER BY `AngelTypes`.`name`",
            sql_escape($shiftId)
        )
    );
}

/**
 * Removes al angel types by its shift id.
 *
 * @param $shiftId
 */
function removeNeededAngelTypeByShift($shiftId)
{
    sql_query(sprintf("DELETE FROM `NeededAngelTypes` WHERE `shift_id`='%s'", sql_escape($shiftId)));
}

/**
 * Creates needed Angel types per shift.
 *
 * @param $shiftId
 * @param $typeId
 * @param $count
 */
function createNeededAngelTypeInShift($shiftId, $typeId, $count)
{
    sql_query(
        sprintf(
            "INSERT INTO `NeededAngelTypes` SET `shift_id`='%s', `angel_type_id`='%s', `count`='%s'",
            sql_escape($shiftId),
            sql_escape($typeId),
            sql_escape($count)
        )
    );
}
