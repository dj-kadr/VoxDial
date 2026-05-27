# VoxDial
Автоматичний обзвон в чергу або IVR зі статистикою 

1 /etc/asterisk/extensions_custom.conf
[dialer-outbound]
; Контекст виклику клієнта. Якщо він не підняв трубку,
; Asterisk автоматично йде далі по пріоритетах вниз на status-handler.
exten => _X.,1,NoOp(--- VoxDial: Вызов абонента ${EXTEN} для лида #{$LEAD_ID} ---)
 same => n,Set(TIMEOUT(absolute)=3600)
 same => n,Dial(${DIAL_TRUNK}/${EXTEN},${RING_TIME},g)
 ; Если клиент НЕ поднял трубку (BUSY, NOANSWER и т.д.), мы попадаем сюда:
 same => n,Goto(status-handler,s,1)

[dialer-priority-queue]
exten => s,1,NoOp(--- VoxDial: Абонент ответил, направляем в очередь ${QUEUE_NUM} ---)
 same => n,Set(CLIENT_NUM=${CUT(CUT(CHANNEL,/,2),@,1)})
 same => n,Set(CALLERID(num)=${CLIENT_NUM})
 same => n,Set(CALLERID(name)=${CLIENT_NUM})
 ; Опція 'F' прибирається. Якщо канал буде оптимізовано (Move-swap), він просто тихо закриється.
 same => n,Queue(${QUEUE_NUM},T,,,30)
 same => n,Hangup()

;[dialer-priority-queue]
;exten => s,1,NoOp(--- VoxDial: Абонент ответил, направляем в очередь ${QUEUE_NUM} ---)
; ; --- НАЧАЛО ИСПРАВЛЕНИЯ CALLERID ---
; ; Вырезаем номер мобильного клиента из имени канала (например, из "Local/0964469648@...")
; same => n,Set(CLIENT_NUM=${CUT(CUT(CHANNEL,/,2),@,1)})
; ; Устанавливаем его как CallerID для оператора
; same => n,Set(CALLERID(num)=${CLIENT_NUM})
; same => n,Set(CALLERID(name)=${CLIENT_NUM})
; ; --- КОНЕЦ ИСПРАВЛЕНИЯ ---
; same => n,Set(START_TALK=${EPOCH})
; same => n,Queue(${QUEUE_NUM},gTF,,,30)
; same => n,NoOp(--- VoxDial: Разговор в очереди завершен ---)
; same => n,Goto(h,1)

; Перехватчик успешного окончания разговора
exten => h,1,NoOp(--- VoxDial: Фиксация успешного разговора ---)
 same => n,Set(TALK_DURATION=$[${EPOCH} - ${START_TALK}])
 same => n,System(php /var/www/html/dialer/callback.php ${LEAD_ID} 2 ${TALK_DURATION})
 same => n,Hangup()
 
[status-handler]
; Сюда система попадает ТОЛЬКО при недозвонах (клиент пропустил или занят)
exten => s,1,NoOp(--- VoxDial: Анализ статуса недозвона. Статус: ${DIALSTATUS} ---)
 same => n,GotoIf($["${DIALSTATUS}" = "BUSY"]?busy)
 same => n,GotoIf($["${DIALSTATUS}" = "NOANSWER"]?noanswer)
 same => n,GotoIf($["${DIALSTATUS}" = "CONGESTION"]?error)
 same => n,GotoIf($["${DIALSTATUS}" = "CHANUNAVAIL"]?error)
 same => n,Goto(noanswer)

 same => n(busy),System(php /var/www/html/dialer/callback.php ${LEAD_ID} 3 0)
 same => n,Hangup()

 same => n(noanswer),System(php /var/www/html/dialer/callback.php ${LEAD_ID} 4 0)
 same => n,Hangup()

 same => n(error),System(php /var/www/html/dialer/callback.php ${LEAD_ID} 5 0)
 same => n,Hangup()



Створення бази данних 

-- 1. Создаем базу данных dialer, если она еще не создана
CREATE DATABASE IF NOT EXISTS `dialer` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `dialer`;

-- 2. Создание таблицы кампаний (campaigns)
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `queue_num` VARCHAR(50) NOT NULL,
  `destination_type` ENUM('queue', 'ivr') NOT NULL DEFAULT 'queue',
  `destination_value` VARCHAR(50) DEFAULT NULL,
  `trunk_id` INT(11) DEFAULT NULL,
  `status` TINYINT(4) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `updated_at` DATETIME DEFAULT NULL,
  `max_retries` INT(11) DEFAULT 1,
  `retry_time` INT(11) DEFAULT 10,
  `ring_time` INT(11) DEFAULT 30,
  `channel_limit` INT(11) DEFAULT 5,
  `min_success_duration` INT(11) DEFAULT 10,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Создание таблицы dialer_campaigns (похоже на лог или архив параметров)
CREATE TABLE IF NOT EXISTS `dialer_campaigns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `campaign_name` VARCHAR(100) NOT NULL,
  `max_retries` INT(11) DEFAULT 1,
  `retry_time` INT(11) DEFAULT 10,
  `ring_time` INT(11) DEFAULT 30,
  `channel_limit` INT(11) DEFAULT 5,
  `min_success_duration` INT(11) DEFAULT 10,
  `continue_after_answer` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Создание таблицы лидов/номеров для обзвона (leads)
CREATE TABLE IF NOT EXISTS `leads` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` INT(11) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `status` TINYINT(4) DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  INDEX `idx_campaign_id` (`campaign_id`),
  
  -- Внешний ключ: связывает лиды с конкретной кампанией из таблицы campaigns.
  -- Если кампания удаляется, то удаляются и её лиды (CASCADE).
  CONSTRAINT `fk_leads_campaign` 
    FOREIGN KEY (`campaign_id`) 
    REFERENCES `campaigns` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
