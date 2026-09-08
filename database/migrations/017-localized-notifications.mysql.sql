ALTER TABLE account_notifications
  ADD COLUMN message_params JSON NULL AFTER body;

ALTER TABLE account_notifications
  MODIFY COLUMN title VARCHAR(160) NULL,
  MODIFY COLUMN body VARCHAR(500) NULL;

UPDATE account_notifications
SET message_params = CASE event_type
  WHEN 'MESSAGE_RECEIVED' THEN JSON_OBJECT(
    'senderName', TRIM(TRAILING '.' FROM SUBSTRING(body, CHAR_LENGTH('You received a private message from ') + 1))
  )
  WHEN 'PROFILE_SELECTED' THEN JSON_OBJECT(
    'memberName', SUBSTRING_INDEX(body, ' selected an option from your profile.', 1)
  )
  WHEN 'PRODUCT_PURCHASED' THEN JSON_OBJECT(
    'productName', TRIM(TRAILING '.' FROM SUBSTRING(body, CHAR_LENGTH('A member purchased ') + 1))
  )
  WHEN 'ROLE_CHANGED' THEN JSON_OBJECT(
    'role', TRIM(TRAILING '.' FROM SUBSTRING(body, CHAR_LENGTH('Your account role is now ') + 1))
  )
  ELSE JSON_OBJECT()
END
WHERE message_params IS NULL;
