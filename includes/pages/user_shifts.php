<?php

function shifts_title()
{
    return _("Shifts");
}

/**
 * @param $shiftId
 *
 * @return array
 */
function prepareShiftAndAngelValues($shiftId)
{
    $shift = findShiftById($shiftId);
    if (0 === count($shift)) {
        redirect(page_link_to('user_shifts'));
    }
    $shift = $shift[0];

    // Engeltypen laden
    $allAngelTypes = findAllAngelTypes();
    $angelTypes = array();
    $neededAngelTypes = array();
    foreach ($allAngelTypes as $type) {
        $angelTypes[$type['id']] = $type;
        $neededAngelTypes[$type['id']] = 0;
    }

    $shiftTypesSource = findAllShiftTypes();
    $shiftTypes = [];
    foreach ($shiftTypesSource as $shiftType) {
        $shiftTypes[$shiftType['id']] = $shiftType['name'];
    }

    // Benötigte Engeltypen vom Raum
    $neededAngelTypesSource = findAndCountNeededAngelTypesByRoom($shift['RID']);
    foreach ($neededAngelTypesSource as $type) {
        if ($type['count'] != "") {
            $neededAngelTypes[$type['id']] = $type['count'];
        }
    }

    // Benötigte Engeltypen von der Schicht
    $neededAngelTypesSource = findAndCountNeededAngelTypesByShift($shiftId);
    foreach ($neededAngelTypesSource as $type) {
        if ($type['count'] != "") {
            $neededAngelTypes[$type['id']] = $type['count'];
        }
    }
    return array($shift, $allAngelTypes, $angelTypes, $neededAngelTypes, $shiftTypes, $neededAngelTypesSource);
}

/**
 * Löscht einen einzelenen ShiftEntry Eintrag, was der Belegung eines Engels durch den Admin entspricht.
 *
 * @param int $shiftEntryId
 */
function removeShiftEntryById($shiftEntryId)
{
    if (!test_request_int('entry_id')) {
        redirect(page_link_to('user_shifts'));
    }
    $shift_entry_source = getShiftEntrySourceById($shiftEntryId);
    if (count($shift_entry_source) > 0) {
        $shift_entry_source = $shift_entry_source[0];
        $result = ShiftEntry_delete($shiftEntryId);
        if (!$result) {
            engelsystem_error('Unable to delete shift entry.');
        }
        engelsystem_log(
            sprintf(
                "Deleted %s's shift: %s at %s from %s to %s as %s",
                User_Nick_render($shift_entry_source),
                $shift_entry_source['name'],
                $shift_entry_source['Name'],
                date("Y-m-d H:i", $shift_entry_source['start']),
                date("Y-m-d H:i", $shift_entry_source['end']),
                $shift_entry_source['angel_type']
            )
        );
        success(_("Shift entry deleted."));
    } else {
        error(_("Entry not found."));
    }
    redirect(page_link_to('user_shifts'));
}

/**
 * Edits a given Shift.
 *
 * @param int $editShiftId
 * @param array $rooms
 *
 * @return string
 */
function editShift($editShiftId, $rooms)
{
    $submit = hasRequestKey('submit') ? requestGetByKey('submit') : false;
    $msg = "";
    $ok = true;

    if (!test_request_int('edit_shift')) {
        redirect(page_link_to('user_shifts'));
    }

    list(
        $shift,
        $allAngelTypes,
        $angelTypes,
        $neededAngelTypes,
        $shiftTypes,
        $neededAngelTypesSource) = prepareShiftAndAngelValues($editShiftId);

    $shiftTypeId = $shift['shifttype_id'];
    $title = $shift['title'];
    $rid = $shift['RID'];
    $start = $shift['start'];
    $end = $shift['end'];

    if ($submit) {
        $title = strip_request_item('title');
        $rid = hasRequestKey('rid') ? requestGetByKey('rid') : null;
        $shiftTypeId = hasRequestKey('shifttype_id') ? requestGetByKey('shifttype_id') : null;
        $start = hasRequestKey('start') ? requestGetByKey('start') : null;
        $end = hasRequestKey('end') ? requestGetByKey('end') : null;

        if (null === $rid || preg_match("/^[0-9]+$/", $rid) || !isset($room_array[$rid])) {
            $ok = false;
            $rid = $rooms[0]['RID'];
            $msg .= error(_("Please select a room."), true);
        }

        if (null === $shiftTypeId || isset($shiftTypes[$shiftTypeId])) {
            $ok = false;
            $msg .= error(_('Please select a shifttype.'), true);
        }

        if (null !== $start || $tmp = DateTime::createFromFormat("Y-m-d H:i", trim($start))) {
            $start = $tmp->getTimestamp();
        } else {
            $ok = false;
            $msg .= error(_("Please enter a valid starting time for the shifts."), true);
        }

        if (null !== $end && $tmp = DateTime::createFromFormat("Y-m-d H:i", trim($end))) {
            $end = $tmp->getTimestamp();
        } else {
            $ok = false;
            $msg .= error(_("Please enter a valid ending time for the shifts."), true);
        }

        if ($start >= $end) {
            $ok = false;
            $msg .= error(_("The ending time has to be after the starting time."), true);
        }

        foreach ($neededAngelTypesSource as $type) {
            $requestKey = 'type_' . $type['id'];
            if (hasRequestKey($requestKey) && preg_match("/^[0-9]+$/", trim(requestGetByKey($requestKey)))) {
                $neededAngelTypes[$type['id']] = trim(requestGetByKey($requestKey));
            } else {
                $ok = false;
                $msg .= error(sprintf(_("Please check your input for needed angels of type %s."), $type['name']), true);
            }
        }

        if ($ok) {
            $shift['shifttype_id'] = $shiftTypeId;
            $shift['title'] = $title;
            $shift['RID'] = $rid;
            $shift['start'] = $start;
            $shift['end'] = $end;

            $result = Shift_update($shift);
            if ($result === false) {
                engelsystem_error('Unable to update shift.');
            }
            removeNeededAngelTypeByShift($editShiftId);
            $neededAngelTypesInfo = array();
            foreach ($neededAngelTypes as $typeId => $count) {
                createNeededAngelTypeInShift($editShiftId, $typeId, $count);
                $neededAngelTypesInfo[] = $angelTypes[$typeId]['name'] . ": " . $count;
            }

            $name = '' !== $title ? $title : $editShiftId;
            engelsystem_log(
                sprintf(
                    "Updated shift '%s' from  to %s with angel types %s",
                    $name,
                    date("Y-m-d H:i", $start),
                    date("Y-m-d H:i", $end),
                    join(", ", $neededAngelTypesInfo)
                )
            );
            success(_("Shift updated."));

            redirect(shift_link([
                'SID' => $editShiftId
            ]));
        }
    }

    $angelTypes = "";
    foreach ($allAngelTypes as $type) {
        $angelTypes .= form_spinner('type_' . $type['id'], $type['name'], $neededAngelTypes[$type['id']]);
    }

    return page_with_title(shifts_title(), array(
        msg(),
        '<noscript>' . info(_("This page is much more comfortable with javascript."), true) . '</noscript>',
        form(array(
            form_select('shifttype_id', _('Shifttype'), $shiftTypes, $shiftTypeId),
            form_text('title', _("Title"), $title),
            form_select('rid', _("Location:"), $room_array, $rid),
            form_text('start', _("Start:"), date("Y-m-d H:i", $start)),
            form_text('end', _("End:"), date("Y-m-d H:i", $end)),
            '<h2>' . _("Needed angels") . '</h2>',
            $angelTypes,
            form_submit('submit', _("Save"))
        ))
    ));
}

/**
 * @param $shiftId
 *
 * @return string
 */
function removeShiftOnPage($shiftId)
{
    if (!preg_match("/^[0-9]*$/", $shiftId)) {
        redirect(page_link_to('user_shifts'));
    }

    $shift = findShift($shiftId);
    if ($shift === false) {
        engelsystem_error('Unable to load shift.');
    }
    if ($shift == null) {
        redirect(page_link_to('user_shifts'));
    }

    $delete = hasRequestKey('delete') ? requestGetByKey('delete') : false;
    if ($delete) {
        $result = removeShift($shiftId);
        if ($result === false) {
            engelsystem_error('Unable to delete shift.');
        }

        engelsystem_log(
            sprintf(
                "Deleted shift %s from %s to %s",
                $shift['name'],
                date("Y-m-d H:i", $shift['start']),
                date("Y-m-d H:i", $shift['end'])
            )
        );
        success(_("Shift deleted."));
        redirect(page_link_to('user_shifts'));
    }

    return page_with_title(shifts_title(), array(
        error(sprintf(_("Do you want to delete the shift %s from %s to %s?"), $shift['name'], date("Y-m-d H:i", $shift['start']), date("H:i", $shift['end'])), true),
        '<a class="button" href="?p=user_shifts&delete_shift=' . $shiftId . '&delete">' . _("delete") . '</a>'
    ));
}

/**
 * Builds and prepares the Shift entry edit view.
 *
 * @param $shiftId
 * @param $room_array
 * @return string
 */
function buildShiftEntryEditView($shiftId, $room_array)
{
    global $user, $privileges;

    if (!preg_match("/^[0-9]*$/", $shiftId)) {
        redirect(page_link_to('user_shifts'));
    }
    $shift = findShift($shiftId);
    $shift['Name'] = $room_array[$shift['RID']];
    if (!$shift) {
        engelsystem_error('Unable to load shift.');
    }
    if ($shift == null) {
        redirect(page_link_to('user_shifts'));
    }
    $typeId = hasRequestKey('type_id') ? requestGetByKey('type_id') : null;
    if (null === $typeId || preg_match("/^[0-9]*$/", $typeId)) {
        redirect(page_link_to('user_shifts'));
    }
    $type = in_array('user_shifts_admin', $privileges) ? findAngelTypeById($typeId) : UserAngelType($user['UID']);

    if (count($type) == 0) {
        redirect(page_link_to('user_shifts'));
    }
    $type = $type[0];

    if (!Shift_signup_allowed($shift, $type)) {
        error(_('You are not allowed to sign up for this shift. Maybe shift is full or already running.'));
        redirect(shift_link($shift));
    }

    if (hasRequestKey('submit')) {
        $selected_type_id = $typeId;
        if (in_array('user_shifts_admin', $privileges)) {
            if (hasRequestKey('user_id') && preg_match("/^[0-9]*$/", requestGetByKey('user_id'))) {
                $user_id = requestGetByKey('user_id');
            } else {
                $user_id = $user['UID'];
            }

            if (sql_num_query("SELECT * FROM `User` WHERE `UID`='" . sql_escape($user_id) . "' LIMIT 1") == 0) {
                redirect(page_link_to('user_shifts'));
            }

            if (hasRequestKey('angeltype_id')
                && test_request_int('angeltype_id')
                && sql_num_query(
                    sprintf(
                        "SELECT * FROM `AngelTypes` WHERE `id`='%s' LIMIT 1",
                        sql_escape(requestGetByKey('angeltype_id'))
                    )
                ) > 0
            ) {
                $selected_type_id = requestGetByKey('angeltype_id');
            } else {
                $user_id = $user['UID'];
            }

            $countShiftEntries = sql_num_query(
                sprintf(
                    "SELECT * FROM `ShiftEntry` WHERE `SID`='%s' AND `UID` = '%s'",
                    sql_escape($shift['SID']),
                    sql_escape($user_id)
                )
            );
            if ($countShiftEntries) {
                return error("This angel does already have an entry for this shift.", true);
            }

            $freeloaded = $shift['freeloaded'];
            $freeload_comment = $shift['freeload_comment'];
            if (in_array("user_shifts_admin", $privileges)) {
                $freeloaded = hasRequestKey('freeloaded');
                $freeload_comment = strip_request_item_nl('freeload_comment');
            }

            $comment = strip_request_item_nl('comment');
            $result = ShiftEntry_create(array(
                'SID' => $shiftId,
                'TID' => $selected_type_id,
                'UID' => $user_id,
                'Comment' => $comment,
                'freeloaded' => $freeloaded,
                'freeload_comment' => $freeload_comment
            ));
            if ($result === false) {
                engelsystem_error('Unable to create shift entry.');
            }

            $countUserAngelTypes = sql_num_query(
                sprintf(
                    "SELECT * FROM `UserAngelTypes`
                            INNER JOIN `AngelTypes` ON `AngelTypes`.`id` = `UserAngelTypes`.`angeltype_id`
                            WHERE `angeltype_id` = '%s' AND `user_id` = '%s' ",
                    sql_escape($selected_type_id),
                    sql_escape($user_id)
                )
            );
            if ($type['restricted'] == 0 && $countUserAngelTypes == 0) {
                sql_query(
                    sprintf(
                        "INSERT INTO `UserAngelTypes` (`user_id`, `angeltype_id`) VALUES ('%s', '%s')",
                        sql_escape($user_id),
                        sql_escape($selected_type_id)
                    )
                );
            }

            $userSource = findUserById($user_id);
            engelsystem_log(
                sprintf(
                    "User %s signed up for shift %s from %s to %s",
                    User_Nick_render($userSource),
                    $shift['name'],
                    date("Y-m-d H:i", $shift['start']),
                    date("Y-m-d H:i", $shift['end'])
                )
            );
            success(
                sprintf(
                    '%s <a href="%s">%s &raquo;</a>',
                    _("You are subscribed. Thank you!"),
                    page_link_to('user_myshifts'),
                    _("My shifts")
                )
            );
            redirect(shift_link($shift));
        }
    }

    if (in_array('user_shifts_admin', $privileges)) {
        $users = sql_select(
            "SELECT *, (SELECT count(*)
                    FROM `ShiftEntry`
                    WHERE `freeloaded`=1 AND `ShiftEntry`.`UID`=`User`.`UID`) AS `freeloaded`
                FROM `User` ORDER BY `Nick`"
        );
        $usersSelect = array();

        foreach ($users as $usr) {
            $usersSelect[$usr['UID']] = $usr['Nick'] . ($usr['freeloaded'] == 0 ? "" : " (" . _("Freeloader") . ")");
        }
        $userText = html_select_key('user_id', 'user_id', $usersSelect, $user['UID']);

        $angeTypesSource = sql_select("SELECT * FROM `AngelTypes` ORDER BY `name`");
        $angelTypes = array();
        foreach ($angeTypesSource as $angelType) {
            $angelTypes[$angelType['id']] = $angelType['name'];
        }
        $angelTypeSelect = html_select_key('angeltype_id', 'angeltype_id', $angelTypes, $type['id']);
    } else {
        $userText = User_Nick_render($user);
        $angelTypeSelect = $type['name'];
    }

    return ShiftEntry_edit_view(
        $userText,
        date("Y-m-d H:i", $shift['start']) . ' &ndash; ' . date('Y-m-d H:i', $shift['end']) . ' (' . shift_length($shift) . ')',
        $shift['Name'],
        $shift['name'],
        $angelTypeSelect,
        "",
        false,
        null,
        in_array('user_shifts_admin', $privileges)
    );
}

/**
 * Kind of a router based on the REQUEST values.
 *
 * @return string
 */
function user_shifts()
{
    global $user, $privileges;

    if (User_is_freeloader($user)) {
        redirect(page_link_to('user_myshifts'));
    }

    $rooms = activeRooms();
    if (hasRequestKey('entry_id') && in_array('user_shifts_admin', $privileges)) {
        removeShiftEntryById(requestGetByKey('entry_id'));
    } elseif (hasRequestKey('entry_id') && in_array('admin_shifts', $privileges)) {
        return editShift(requestGetByKey('entry_id'), $rooms);
    } elseif (hasRequestKey('delete_shift') && in_array('user_shifts_admin', $privileges)) {
        removeShiftOnPage(requestGetByKey('delete_shift'));
    } elseif (hasRequestKey('shift_id')) {
        $room_array = array();
        foreach ($rooms as $room) {
            $room_array[$room['RID']] = $room['Name'];
        }

        return buildShiftEntryEditView(requestGetByKey('shift_id'), $room_array);
    } else {
        return view_user_shifts();
    }
}

function view_user_shifts()
{
    global $user, $privileges;
    global $ical_shifts;

    $ical_shifts = array();
    $days = sql_select_single_col("
      SELECT DISTINCT DATE(FROM_UNIXTIME(`start`)) AS `id`, DATE(FROM_UNIXTIME(`start`)) AS `name`
      FROM `Shifts`
      ORDER BY `start`");

    if (count($days) == 0) {
        error(_("The administration has not configured any shifts yet."));
        redirect('?');
    }

    $rooms = sql_select("SELECT `RID` AS `id`, `Name` AS `name` FROM `Room` WHERE `show`='Y' ORDER BY `Name`");

    if (count($rooms) == 0) {
        error(_("The administration has not configured any locations yet."));
        redirect('?');
    }

    if (in_array('user_shifts_admin', $privileges)) {
        $types = sql_select("SELECT `id`, `name` FROM `AngelTypes` ORDER BY `AngelTypes`.`name`");
    } else {
        $types = sql_select("SELECT `AngelTypes`.`id`, `AngelTypes`.`name`, (`AngelTypes`.`restricted`=0 OR (NOT `UserAngelTypes`.`confirm_user_id` IS NULL OR `UserAngelTypes`.`id` IS NULL)) as `enabled` FROM `AngelTypes` LEFT JOIN `UserAngelTypes` ON (`UserAngelTypes`.`angeltype_id`=`AngelTypes`.`id` AND `UserAngelTypes`.`user_id`='" . sql_escape($user['UID']) . "') ORDER BY `AngelTypes`.`name`");
    }

    if (empty($types)) {
        $types = sql_select("SELECT `id`, `name` FROM `AngelTypes` WHERE `restricted` = 0");
    }
    $filled = array(
        array(
            'id' => '1',
            'name' => _('occupied')
        ),
        array(
            'id' => '0',
            'name' => _('free')
        )
    );

    if (count($types) == 0) {
        error(_("The administration has not configured any angeltypes yet - or you are not subscribed to any angeltype."));
        redirect('?');
    }

    if (!isset($_SESSION['user_shifts'])) {
        $_SESSION['user_shifts'] = array();
    }

    if (!isset($_SESSION['user_shifts']['filled'])) {
        // User shift admins see free and occupied shifts by default
        $_SESSION['user_shifts']['filled'] = in_array('user_shifts_admin', $privileges) ? [0, 1] : [0];
    }

    $keys = array('rooms', 'types', 'filled');
    foreach ($keys as $key) {
        if (hasRequestKey($key)) {
            $filtered = array_filter(requestGetByKey($key), 'is_numeric');
            if (!empty($filtered)) {
                $_SESSION['user_shifts'][$key] = $filtered;
            }
            unset($filtered);
        }
        if (!isset($_SESSION['user_shifts'][$key])) {
            $_SESSION['user_shifts'][$key] = array_map(function ($array) {
                return $array["id"];
            }, $$key);
        }
    }

    if (hasRequestKey('rooms')) {
        $_SESSION['user_shifts']['new_style'] = hasRequestKey('new_style');
    }
    if (!isset($_SESSION['user_shifts']['new_style'])) {
        $_SESSION['user_shifts']['new_style'] = true;
    }
    $keys = array( 'start', 'end');
    foreach ($keys as $key) {
        $dayKey = $key . '_day';
        if (hasRequestKey($dayKey) && in_array(requestGetByKey($dayKey), $days)) {
            $_SESSION['user_shifts'][$dayKey] = requestGetByKey($dayKey);
        }
        $timeKey = $key . '_time';
        if (hasRequestKey($timeKey) && preg_match('#^\d{1,2}:\d\d$#', requestGetByKey($timeKey))) {
            $_SESSION['user_shifts'][$timeKey] = requestGetByKey($timeKey);
        }
        if (!isset($_SESSION['user_shifts'][$key . '_day'])) {
            $time = date('Y-m-d', time() + ($key == 'end' ? 24 * 60 * 60 : 0));
            $_SESSION['user_shifts'][$key . '_day'] = in_array($time, $days) ? $time : ($key == 'end' ? max($days) : min($days));
        }
        if (!isset($_SESSION['user_shifts'][$key . '_time'])) {
            $_SESSION['user_shifts'][$key . '_time'] = date('H:i');
        }
    }

    if ($_SESSION['user_shifts']['start_day'] > $_SESSION['user_shifts']['end_day']) {
        $_SESSION['user_shifts']['end_day'] = $_SESSION['user_shifts']['start_day'];
    }
    if ($_SESSION['user_shifts']['start_day'] == $_SESSION['user_shifts']['end_day']
        && $_SESSION['user_shifts']['start_time'] >= $_SESSION['user_shifts']['end_time']
    ) {
        $_SESSION['user_shifts']['end_time'] = '23:59';
    }

    if (isset($_SESSION['user_shifts']['start_day'])) {
        $startTime = DateTime::createFromFormat("Y-m-d H:i", $_SESSION['user_shifts']['start_day'] . $_SESSION['user_shifts']['start_time']);
        $startTime = $startTime->getTimestamp();
    } else {
        $startTime = now();
    }

    if (isset($_SESSION['user_shifts']['end_day'])) {
        $endTime = DateTime::createFromFormat("Y-m-d H:i", $_SESSION['user_shifts']['end_day'] . $_SESSION['user_shifts']['end_time']);
        $endTime = $endTime->getTimestamp();
    } else {
        $endTime = now() + 24 * 60 * 60;
    }

    if (!isset($_SESSION['user_shifts']['rooms']) || count($_SESSION['user_shifts']['rooms']) == 0) {
        $_SESSION['user_shifts']['rooms'] = array(0);
    }

    $SQL = sprintf(
        "SELECT DISTINCT `Shifts`.*, `ShiftTypes`.`name`, `Room`.`Name` as `room_name`, nat2.`special_needs` > 0 AS 'has_special_needs'
        FROM `Shifts`
        INNER JOIN `Room` USING (`RID`)
        INNER JOIN `ShiftTypes` ON (`ShiftTypes`.`id` = `Shifts`.`shifttype_id`)
        LEFT JOIN (SELECT COUNT(*) AS special_needs , nat3.`shift_id` FROM `NeededAngelTypes` AS nat3 WHERE `shift_id` IS NOT NULL GROUP BY nat3.`shift_id`) AS nat2 ON nat2.`shift_id` = `Shifts`.`SID`
        INNER JOIN `NeededAngelTypes` AS nat ON nat.`count` != 0
        AND nat.`angel_type_id` IN (%s) AND ((nat2.`special_needs` > 0
        AND nat.`shift_id` = `Shifts`.`SID`) OR ((nat2.`special_needs` = 0 OR nat2.`special_needs` IS NULL)
        AND nat.`room_id` = `RID`))
        LEFT JOIN
        (SELECT se.`SID`, se.`TID`, COUNT(*) as count FROM `ShiftEntry` AS se GROUP BY se.`SID`, se.`TID`) AS entries
        ON entries.`SID` = `Shifts`.`SID` AND entries.`TID` = nat.`angel_type_id`
        WHERE `Shifts`.`RID` IN (%s)
        AND `start` BETWEEN %s AND %s",
        implode(',', $_SESSION['user_shifts']['types']),
        implode(',', $_SESSION['user_shifts']['rooms']),
        $startTime,
        $endTime
    );

    if (count($_SESSION['user_shifts']['filled']) == 1) {
        if ($_SESSION['user_shifts']['filled'][0] == 0) {
            $SQL .= "
                AND (nat.`count` > entries.`count` OR entries.`count` IS NULL OR EXISTS (SELECT `SID` FROM `ShiftEntry` WHERE `UID` = '" . sql_escape($user['UID']) . "' AND `ShiftEntry`.`SID` = `Shifts`.`SID`))";
        } elseif ($_SESSION['user_shifts']['filled'][0] == 1) {
            $SQL .= "
                AND (nat.`count` <= entries.`count`  OR EXISTS (SELECT `SID` FROM `ShiftEntry` WHERE `UID` = '" . sql_escape($user['UID']) . "' AND `ShiftEntry`.`SID` = `Shifts`.`SID`))";
        }
    }
    $SQL .= "ORDER BY `start`";

    $shifts = sql_select($SQL);

    $ownShiftsSource = sql_select(
        sprintf(
            "SELECT `ShiftTypes`.`name`, `Shifts`.*
            FROM `Shifts`
            INNER JOIN `ShiftTypes` ON (`ShiftTypes`.`id` = `Shifts`.`shifttype_id`)
            INNER JOIN `ShiftEntry` ON (`Shifts`.`SID` = `ShiftEntry`.`SID` AND `ShiftEntry`.`UID` = '%s')
            WHERE `Shifts`.`RID` IN (%s)
            AND `start` BETWEEN %s AND %s",
            sql_escape($user['UID']),
            implode(',', $_SESSION['user_shifts']['rooms']),
            $startTime,
            $endTime
        )
    );
    $ownShifts = array();
    foreach ($ownShiftsSource as $ownshift) {
        $ownShifts[$ownshift['SID']] = $ownshift;
    }
    unset($ownShiftsSource);

    $shiftsTable = "";

    /*
     * [0] => Array (
     *    [SID] => 1,
     *    [start] => 1355958000,
     *    [end]   => 1355961600,
     *    [RID]   => 1,
     *    [name]  => '',
     *    [URL]   => '',
     *    [PSID] => '',
     *    [room_name] =>  test1,
     *    [has_special_needs] => 1,
     *    [is_full] => 0,
     * )
     */
    if ($_SESSION['user_shifts']['new_style']) {
        $first = 15 * 60 * floor($startTime / (15 * 60));
        $maxshow = ceil(($endTime - $first) / (60 * 15));
        $block = array();
        $todo = array();
        $myRooms = $rooms;

        // delete un-selected rooms from array
        foreach ($myRooms as $k => $v) {
            if (array_search($v["id"], $_SESSION['user_shifts']['rooms']) === FALSE) {
                unset($myRooms[$k]);
            }
            // initialize $block array
            $block[$v["id"]] = array_fill(0, $maxshow, 0);
        }

        // calculate number of parallel shifts in each timeslot for each room
        foreach ($shifts as $k => $shift) {
            $roomId = $shift["RID"];
            $blocks = ($shift["end"] - $shift["start"]) / (15 * 60);
            $firstBlock = floor(($shift["start"] - $first) / (15 * 60));
            for ($i = $firstBlock; $i < $blocks + $firstBlock && $i < $maxshow; $i++) {
                $block[$roomId][$i]++;
            }
            $shifts[$k]['own'] = in_array($shift['SID'], array_keys($ownShifts));
        }

        $shiftsTable = '<div class="shifts-table"><table id="shifts" class="table scrollable"><thead><tr><th>-</th>';
        foreach ($myRooms as $key => $room) {
            $roomId = $room["id"];
            if (array_sum($block[$roomId]) == 0) {
                // do not display columns without entries
                unset($block[$roomId]);
                unset($myRooms[$key]);
                continue;
            }
            $colspan = call_user_func_array('max', $block[$roomId]);
            if ($colspan == 0) {
                $colspan = 1;
            }
            $todo[$roomId] = array_fill(0, $maxshow, $colspan);
            $shiftsTable .= sprintf(
                "<th%s>%s</th>\n",
                (($colspan > 1) ? ' colspan="' . $colspan . '"' : ''),
                Room_name_render([
                    'RID' => $room['id'],
                    'Name' => $room['name']
                ]));
        }
        unset($block, $blocks, $firstBlock, $colspan, $key, $room);

        $shiftsTable .= "</tr></thead><tbody>";
        for ($i = 0; $i < $maxshow; $i++) {
            $thistime = $first + ($i * 15 * 60);
            if ($thistime % (24 * 60 * 60) == 23 * 60 * 60 && $endTime - $startTime > 24 * 60 * 60) {
                $shiftsTable .= "<tr class=\"row-day\"><th class=\"row-header\">";
                $shiftsTable .= date('Y-m-d<b\r />H:i', $thistime);
            } elseif ($thistime % (60 * 60) == 0) {
                $shiftsTable .= "<tr class=\"row-hour\"><th>";
                $shiftsTable .= date("H:i", $thistime);
            } else {
                $shiftsTable .= "<tr><th>";
            }
            $shiftsTable .= "</th>";
            foreach ($myRooms as $room) {
                $roomId = $room["id"];
                foreach ($shifts as $shift) {
                    if ($shift["RID"] == $roomId) {
                        if (floor($shift["start"] / (15 * 60)) == $thistime / (15 * 60)) {
                            $blocks = ($shift["end"] - $shift["start"]) / (15 * 60);
                            if ($blocks < 1)
                                $blocks = 1;

                            $collides = in_array($shift['SID'], array_keys($ownShifts));
                            if (!$collides)
                                foreach ($ownShifts as $ownshift) {
                                    if ($ownshift['start'] >= $shift['start'] && $ownshift['start'] < $shift['end'] || $ownshift['end'] > $shift['start'] && $ownshift['end'] <= $shift['end'] || $ownshift['start'] < $shift['start'] && $ownshift['end'] > $shift['end']) {
                                        $collides = true;
                                        break;
                                    }
                                }

                            // qqqqqq
                            $is_free = false;
                            $shifts_row = '';
                            if (in_array('admin_shifts', $privileges))
                                $shifts_row .= '<div class="pull-right">' . table_buttons(array(
                                        button(page_link_to('user_shifts') . '&edit_shift=' . $shift['SID'], glyph('edit'), 'btn-xs'),
                                        button(page_link_to('user_shifts') . '&delete_shift=' . $shift['SID'], glyph('trash'), 'btn-xs')
                                    )) . '</div>';
                            $shifts_row .= Room_name_render([
                                    'RID' => $room['id'],
                                    'Name' => $room['name']
                                ]) . '<br />';
                            $shifts_row .= '<a href="' . shift_link($shift) . '">' . date('Y-m-d H:i', $shift['start']);
                            $shifts_row .= " &ndash; ";
                            $shifts_row .= date('H:i', $shift['end']);
                            $shifts_row .= "<br /><b>";
                            $shifts_row .= ShiftType($shift['shifttype_id'])['name'];
                            $shifts_row .= "</b><br />";
                            if ($shift['title'] != '') {
                                $shifts_row .= $shift['title'];
                                $shifts_row .= "<br />";
                            }
                            $shifts_row .= '</a>';
                            $shifts_row .= '<br />';
                            $query = "SELECT `NeededAngelTypes`.`count`, `AngelTypes`.`id`, `AngelTypes`.`restricted`, `UserAngelTypes`.`confirm_user_id`, `AngelTypes`.`name`, `UserAngelTypes`.`user_id`
            FROM `NeededAngelTypes`
            JOIN `AngelTypes` ON (`NeededAngelTypes`.`angel_type_id` = `AngelTypes`.`id`)
            LEFT JOIN `UserAngelTypes` ON (`NeededAngelTypes`.`angel_type_id` = `UserAngelTypes`.`angeltype_id`AND `UserAngelTypes`.`user_id`='" . sql_escape($user['UID']) . "')
            WHERE
            `count` > 0
            AND ";
                            if ($shift['has_special_needs'])
                                $query .= "`shift_id` = '" . sql_escape($shift['SID']) . "'";
                            else
                                $query .= "`room_id` = '" . sql_escape($shift['RID']) . "'";
                            if (!empty($_SESSION['user_shifts']['types']))
                                $query .= " AND `angel_type_id` IN (" . implode(',', $_SESSION['user_shifts']['types']) . ") ";
                            $query .= " ORDER BY `AngelTypes`.`name`";
                            $angeltypes = sql_select($query);

                            if (count($angeltypes) > 0) {
                                foreach ($angeltypes as $angeltype) {
                                    $entries = sql_select("SELECT * FROM `ShiftEntry` JOIN `User` ON (`ShiftEntry`.`UID` = `User`.`UID`) WHERE `SID`='" . sql_escape($shift['SID']) . "' AND `TID`='" . sql_escape($angeltype['id']) . "' ORDER BY `Nick`");
                                    $entry_list = array();
                                    $freeloader = 0;
                                    foreach ($entries as $entry) {
                                        $style = '';
                                        if ($entry['freeloaded']) {
                                            $freeloader++;
                                            $style = " text-decoration: line-through;";
                                        }
                                        if (in_array('user_shifts_admin', $privileges))
                                            $entry_list[] = "<span style=\"$style\">" . User_Nick_render($entry) . ' ' . table_buttons(array(
                                                    button(page_link_to('user_shifts') . '&entry_id=' . $entry['id'], glyph('trash'), 'btn-xs')
                                                )) . '</span>';
                                        else
                                            $entry_list[] = "<span style=\"$style\">" . User_Nick_render($entry) . "</span>";
                                    }
                                    if ($angeltype['count'] - count($entries) - $freeloader > 0) {
                                        $inner_text = sprintf(ngettext("%d helper needed", "%d helpers needed", $angeltype['count'] - count($entries)), $angeltype['count'] - count($entries));
                                        // is the shift still running or alternatively is the user shift admin?
                                        $user_may_join_shift = true;

                                        // you cannot join if user alread joined a parallel or this shift
                                        $user_may_join_shift &= !$collides;

                                        // you cannot join if user is not of this angel type
                                        $user_may_join_shift &= isset($angeltype['user_id']);

                                        // you cannot join if you are not confirmed
                                        if ($angeltype['restricted'] == 1 && isset($angeltype['user_id']))
                                            $user_may_join_shift &= isset($angeltype['confirm_user_id']);

                                        // you can only join if the shift is in future or running
                                        $user_may_join_shift &= time() < $shift['start'];

                                        // User shift admins may join anybody in every shift
                                        $user_may_join_shift |= in_array('user_shifts_admin', $privileges);
                                        if ($user_may_join_shift)
                                            $entry_list[] = '<a href="' . page_link_to('user_shifts') . '&amp;shift_id=' . $shift['SID'] . '&amp;type_id=' . $angeltype['id'] . '">' . $inner_text . '</a> ' . button(page_link_to('user_shifts') . '&amp;shift_id=' . $shift['SID'] . '&amp;type_id=' . $angeltype['id'], _('Sign up'), 'btn-xs');
                                        else {
                                            if (time() > $shift['start'])
                                                $entry_list[] = $inner_text . ' (' . _('ended') . ')';
                                            elseif ($angeltype['restricted'] == 1 && isset($angeltype['user_id']) && !isset($angeltype['confirm_user_id']))
                                                $entry_list[] = $inner_text . glyph('lock');
                                            elseif ($angeltype['restricted'] == 1)
                                                $entry_list[] = $inner_text;
                                            elseif ($collides)
                                                $entry_list[] = $inner_text;
                                            else
                                                $entry_list[] = $inner_text . '<br />' . button(page_link_to('user_angeltypes') . '&action=add&angeltype_id=' . $angeltype['id'], sprintf(_('Become %s'), $angeltype['name']), 'btn-xs');
                                        }

                                        unset($inner_text);
                                        $is_free = true;
                                    }

                                    $shifts_row .= '<strong>' . AngelType_name_render($angeltype) . ':</strong> ';
                                    $shifts_row .= join(", ", $entry_list);
                                    $shifts_row .= '<br />';
                                }
                                if (in_array('user_shifts_admin', $privileges))
                                    $shifts_row .= ' ' . button(page_link_to('user_shifts') . '&amp;shift_id=' . $shift['SID'] . '&amp;type_id=' . $angeltype['id'], _("Add more angels"), 'btn-xs');
                            }
                            if ($shift['own'] && !in_array('user_shifts_admin', $privileges))
                                $class = 'own';
                            elseif ($collides && !in_array('user_shifts_admin', $privileges))
                                $class = 'collides';
                            elseif ($is_free)
                                $class = 'free';
                            else
                                $class = 'occupied';
                            $shiftsTable .= '<td rowspan="' . $blocks . '" class="' . $class . '">';
                            $shiftsTable .= $shifts_row;
                            $shiftsTable .= "</td>";
                            for ($j = 0; $j < $blocks && $i + $j < $maxshow; $j++) {
                                $todo[$roomId][$i + $j]--;
                            }
                        }
                    }
                }
                // fill up row with empty <td>
                while ($todo[$roomId][$i]-- > 0)
                    $shiftsTable .= '<td class="empty"></td>';
            }
            $shiftsTable .= "</tr>\n";
        }
        $shiftsTable .= '</tbody></table></div>';
        // qqq
    } else {
        $shiftsTable = array();
        foreach ($shifts as $shift) {
            $info = array();
            if ($_SESSION['user_shifts']['start_day'] != $_SESSION['user_shifts']['end_day'])
                $info[] = date("Y-m-d", $shift['start']);
            $info[] = date("H:i", $shift['start']) . ' - ' . date("H:i", $shift['end']);
            if (count($_SESSION['user_shifts']['rooms']) > 1)
                $info[] = Room_name_render([
                    'Name' => $shift['room_name'],
                    'RID' => $shift['RID']
                ]);

            $shift_row = array(
                'info' => join('<br />', $info),
                'entries' => '<a href="' . shift_link($shift) . '">' . $shift['name'] . '</a>' . ($shift['title'] ? '<br />' . $shift['title'] : '')
            );

            if (in_array('admin_shifts', $privileges))
                $shift_row['info'] .= ' ' . table_buttons(array(
                        button(page_link_to('user_shifts') . '&edit_shift=' . $shift['SID'], glyph('edit'), 'btn-xs'),
                        button(page_link_to('user_shifts') . '&delete_shift=' . $shift['SID'], glyph('trash'), 'btn-xs')
                    ));
            $shift_row['entries'] .= '<br />';
            $is_free = false;
            $shift_has_special_needs = 0 < sql_num_query("SELECT `id` FROM `NeededAngelTypes` WHERE `shift_id` = " . $shift['SID']);
            $query = "SELECT `NeededAngelTypes`.`count`, `AngelTypes`.`id`, `AngelTypes`.`restricted`, `UserAngelTypes`.`confirm_user_id`, `AngelTypes`.`name`, `UserAngelTypes`.`user_id`
    FROM `NeededAngelTypes`
    JOIN `AngelTypes` ON (`NeededAngelTypes`.`angel_type_id` = `AngelTypes`.`id`)
    LEFT JOIN `UserAngelTypes` ON (`NeededAngelTypes`.`angel_type_id` = `UserAngelTypes`.`angeltype_id`AND `UserAngelTypes`.`user_id`='" . sql_escape($user['UID']) . "')
    WHERE ";
            if ($shift_has_special_needs)
                $query .= "`shift_id` = '" . sql_escape($shift['SID']) . "'";
            else
                $query .= "`room_id` = '" . sql_escape($shift['RID']) . "'";
            $query .= "               AND `count` > 0 ";
            if (!empty($_SESSION['user_shifts']['types']))
                $query .= "AND `angel_type_id` IN (" . implode(',', $_SESSION['user_shifts']['types']) . ") ";
            $query .= "ORDER BY `AngelTypes`.`name`";
            $angeltypes = sql_select($query);
            if (count($angeltypes) > 0) {
                $my_shift = sql_num_query("SELECT * FROM `ShiftEntry` WHERE `SID`='" . sql_escape($shift['SID']) . "' AND `UID`='" . sql_escape($user['UID']) . "' LIMIT 1") > 0;

                foreach ($angeltypes as &$angeltype) {
                    $entries = sql_select("SELECT * FROM `ShiftEntry` JOIN `User` ON (`ShiftEntry`.`UID` = `User`.`UID`) WHERE `SID`='" . sql_escape($shift['SID']) . "' AND `TID`='" . sql_escape($angeltype['id']) . "' ORDER BY `Nick`");
                    $entry_list = array();
                    $entry_nicks = [];
                    $freeloader = 0;
                    foreach ($entries as $entry) {
                        if (in_array('user_shifts_admin', $privileges))
                            $member = User_Nick_render($entry) . ' ' . table_buttons(array(
                                    button(page_link_to('user_shifts') . '&entry_id=' . $entry['id'], glyph('trash'), 'btn-xs')
                                ));
                        else
                            $member = User_Nick_render($entry);
                        if ($entry['freeloaded']) {
                            $member = '<strike>' . $member . '</strike>';
                            $freeloader++;
                        }
                        $entry_list[] = $member;
                        $entry_nicks[] = $entry['Nick'];
                    }
                    $angeltype['taken'] = count($entries) - $freeloader;
                    $angeltype['angels'] = $entry_nicks;

                    // do we need more angles of this type?
                    if ($angeltype['count'] - count($entries) + $freeloader > 0) {
                        $inner_text = sprintf(ngettext("%d helper needed", "%d helpers needed", $angeltype['count'] - count($entries) + $freeloader), $angeltype['count'] - count($entries) + $freeloader);
                        // is the shift still running or alternatively is the user shift admin?
                        $user_may_join_shift = true;

                        /* you cannot join if user already joined this shift */
                        $user_may_join_shift &= !$my_shift;

                        // you cannot join if user is not of this angel type
                        $user_may_join_shift &= isset($angeltype['user_id']);

                        // you cannot join if you are not confirmed
                        if ($angeltype['restricted'] == 1 && isset($angeltype['user_id']))
                            $user_may_join_shift &= isset($angeltype['confirm_user_id']);

                        // you can only join if the shift is in future or running
                        $user_may_join_shift &= time() < $shift['start'];

                        // User shift admins may join anybody in every shift
                        $user_may_join_shift |= in_array('user_shifts_admin', $privileges);
                        if ($user_may_join_shift)
                            $entry_list[] = '<a href="' . page_link_to('user_shifts') . '&amp;shift_id=' . $shift['SID'] . '&amp;type_id=' . $angeltype['id'] . '">' . $inner_text . ' &raquo;</a>';
                        else {
                            if (time() > $shift['end']) {
                                $entry_list[] = $inner_text . ' (vorbei)';
                            } elseif ($angeltype['restricted'] == 1 && isset($angeltype['user_id']) && !isset($angeltype['confirm_user_id'])) {
                                $entry_list[] = $inner_text . glyph("lock");
                            } else {
                                $entry_list[] = $inner_text . ' <a href="' . page_link_to('user_angeltypes') . '&action=add&angeltype_id=' . $angeltype['id'] . '">' . sprintf(_('Become %s'), $angeltype['name']) . '</a>';
                            }
                        }

                        unset($inner_text);
                        $is_free = true;
                    }

                    $shift_row['entries'] .= '<b>' . $angeltype['name'] . ':</b> ';
                    $shift_row['entries'] .= join(", ", $entry_list);
                    $shift_row['entries'] .= '<br />';
                }
                if (in_array('user_shifts_admin', $privileges)) {
                    $shift_row['entries'] .= '<a href="' . page_link_to('user_shifts') . '&amp;shift_id=' . $shift['SID'] . '&amp;type_id=' . $angeltype['id'] . '">' . _('Add more angels') . ' &raquo;</a>';
                }
                $shiftsTable[] = $shift_row;
                $shift['angeltypes'] = $angeltypes;
                $ical_shifts[] = $shift;
            }
        }
        $shiftsTable = table(array(
            'info' => _("Time") . "/" . _("Location"),
            'entries' => _("Entries")
        ), $shiftsTable);
    }

    if ($user['api_key'] == "") {
        User_reset_api_key($user, false);
    }

    return page(array(
        '<div class="col-md-12">',
        msg(),
        template_render('../templates/user_shifts.html', array(
            'title' => shifts_title(),
            'room_select' => make_select($rooms, $_SESSION['user_shifts']['rooms'], "rooms", _("Location")),
            'start_select' => html_select_key("start_day", "start_day", array_combine($days, $days), $_SESSION['user_shifts']['start_day']),
            'start_time' => $_SESSION['user_shifts']['start_time'],
            'end_select' => html_select_key("end_day", "end_day", array_combine($days, $days), $_SESSION['user_shifts']['end_day']),
            'end_time' => $_SESSION['user_shifts']['end_time'],
            'type_select' => make_select($types, $_SESSION['user_shifts']['types'], "types", _("Angeltypes")),
            'filled_select' => make_select($filled, $_SESSION['user_shifts']['filled'], "filled", _("Occupancy")),
            'task_notice' => '',
            'new_style_checkbox' => '</br><label><input type="checkbox" name="new_style" value="1" ' . ($_SESSION['user_shifts']['new_style'] ? ' checked' : '') . '> ' . _("Use new style if possible") . '</label>',
            'shifts_table' => msg() . $shiftsTable,
            'ical_text' => '<h2>' . _("iCal export") . '</h2><p>' . sprintf(_("Export of shown shifts. <a href=\"%s\">iCal format</a> or <a href=\"%s\">JSON format</a> available (please keep secret, otherwise <a href=\"%s\">reset the api key</a>)."), page_link_to_absolute('ical') . '&key=' . $user['api_key'], page_link_to_absolute('shifts_json_export') . '&key=' . $user['api_key'], page_link_to('user_myshifts') . '&reset') . '</p>',
            'filter' => _("Filter")
        )),
        '</div>'
    ));
}

function make_user_shifts_export_link($page, $key)
{
    $link = "&start_day=" . $_SESSION['user_shifts']['start_day'];
    $link = "&start_time=" . $_SESSION['user_shifts']['start_time'];
    $link = "&end_day=" . $_SESSION['user_shifts']['end_day'];
    $link = "&end_time=" . $_SESSION['user_shifts']['end_time'];
    foreach ($_SESSION['user_shifts']['rooms'] as $room) {
        $link .= '&rooms[]=' . $room;
    }
    foreach ($_SESSION['user_shifts']['types'] as $type) {
        $link .= '&types[]=' . $type;
    }
    foreach ($_SESSION['user_shifts']['filled'] as $filled) {
        $link .= '&filled[]=' . $filled;
    }

    return page_link_to_absolute($page) . $link . '&export=user_shifts&key=' . $key;
}

/**
 * Creates a special select list for the filters.
 *
 * @param $items
 * @param $selected
 * @param string $name
 * @param null $title
 *
 * @return string
 */
function make_select($items, $selected, $name, $title = null)
{
    $html = "";
    if (isset($title)) {
        $html .= '<h4 style="margin-top: 41px;">';
        $html .= $title;
        if ($name == 'types') {
            $html .= ' <small><span class="" data-trigger="hover focus" data-toggle="popover" data-placement="bottom" data-html="true" data-content=\'';
            $html .= _("The tasks shown here are influenced by the preferences you defined in your settings!") . " <a href=\"" . page_link_to('angeltypes') . '&action=about' . "\">" . _("Description of the jobs.") . "</a>";
            $html .= '\'>';
            $html .= glyph('info-sign');
            $html .= '</span></small>';
        }
        $html .= '</h4>';
    }
    $html .= sprintf(
        '<select id="%s" class="%s" name="%s[]" multiple="multiple">',
        uniqid(),
        'filterselect',
        $name
    );

    foreach ($items as $item) {
        $html .= sprintf(
            '<option value="%s"%s>%s%s</option>',
            $item['id'],
            (in_array($item['id'], $selected) ? ' selected="selected"' : ''),
            $item['name'],
            (!isset($item['enabled']) || $item['enabled'] ? '' : " " . htmlentities(glyph("lock")))
        );
    }

    $html .= "</select>";

    return $html;
}
