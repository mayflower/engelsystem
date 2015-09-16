<?php

function Room_name_render($room)
{
    global $privileges;

    if (in_array('admin_rooms', $privileges)) {
        return sprintf('<a href="%s">%s</a>', room_link($room), glyph('map-marker') . $room['Name']);
    }

    return glyph('map-marker') . $room['Name'];
}
