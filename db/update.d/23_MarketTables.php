<?php

if(sql_num_query("SHOW TABLES LIKE 'Market'") == 0) {
    sql_query("CREATE TABLE `Market` (
      `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
      `created` INT NOT NULL ,
      `offeredByUser` INT  NOT NULL ,
      `requestedByAdmin` INT NOT NULL ,
      `title` VARCHAR(255) NOT NULL ,
      `body` TEXT ,
      `fullfilled` ENUM ( '0', '1' ) DEFAULT '0' ,
      KEY `offeredByUser` (`offeredByUser`),
      KEY `requestedByAdmin` (`requestedByAdmin`)
  ) ENGINE = InnoDB DEFAULT CHARSET=utf8 COMMENT='Marktplatz für Angebote und Gesuche' AUTO_INCREMENT=1;");
    sql_query("
      ALTER TABLE `Market`
          ADD CONSTRAINT `market_ibfk_1` FOREIGN KEY (`offeredByUser`) REFERENCES `User` (`UID`) ON DELETE CASCADE ON UPDATE CASCADE,
          ADD CONSTRAINT `market_ibfk_2` FOREIGN KEY (`requestedByAdmin`) REFERENCES `User` (`UID`) ON DELETE CASCADE ON UPDATE CASCADE;
  ");
    $applied = true;

    sql_query("INSERT IGNORE INTO `Privileges` (`name`, `desc`) VALUES ('user_market', 'User view on the market to create offers.')");
    sql_query("INSERT IGNORE INTO `Privileges` (`name`, `desc`) VALUES ('admin_market', 'Admin view on the market to create offers and requests.')");
    $applied = mysql_affected_rows() > 0;
}
