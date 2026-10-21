<?php

function record_admin_action(
    mysqli $connection,
    string $action,
    string $entityType,
    ?int $entityId,
    string $details,
    string $performedBy
): void {
    $statement = $connection->prepare(
        'INSERT INTO admin_audit_logs (action, entity_type, entity_id, details, performed_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $statement->bind_param('ssiss', $action, $entityType, $entityId, $details, $performedBy);
    $statement->execute();
    $statement->close();
}
