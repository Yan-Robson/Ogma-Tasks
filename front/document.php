<?php

include('../../../inc/includes.php');

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

Session::checkLoginUser();

global $DB;

$documentId = (int) ($_GET['docid'] ?? 0);
$forceDownload = isset($_GET['download']);

$document = new Document();
if ($documentId <= 0 || !$document->getFromDB($documentId)) {
    throw new NotFoundHttpException(__('Arquivo não encontrado.', 'tarefas'));
}

$allowed = false;
if ($DB->tableExists('glpi_plugin_tarefas_task_documents')) {
    foreach (
        $DB->request([
            'FROM'  => 'glpi_plugin_tarefas_task_documents',
            'WHERE' => ['documents_id' => $documentId],
        ]) as $link
    ) {
        $task = new PluginTarefasTask();
        if ($task->getFromDB((int) $link['plugin_tarefas_tasks_id']) && $task->canViewItem()) {
            $allowed = true;
            break;
        }
    }
}

if (!$allowed) {
    $ticketId = (int) ($document->fields['tickets_id'] ?? $_GET['tickets_id'] ?? 0);
    if ($ticketId > 0 && $document->canViewFile([
        'itemtype' => Ticket::class,
        'items_id' => $ticketId,
    ])) {
        $allowed = true;
    }
}

if (!$allowed) {
    throw new AccessDeniedHttpException();
}

$path = GLPI_DOC_DIR . '/' . ltrim((string) $document->fields['filepath'], '/');
if (!is_file($path)) {
    throw new NotFoundHttpException(__('Arquivo não encontrado.', 'tarefas'));
}

$filename = (string) ($document->fields['name'] ?: $document->fields['filename'] ?: 'arquivo');
$filename = basename(str_replace(['\\', '/'], '_', $filename));

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detected = (string) $finfo->file($path);
$mime = ($detected !== '' && $detected !== 'application/octet-stream')
    ? $detected
    : (string) ($document->fields['mime'] ?: 'application/octet-stream');

$isImage = str_starts_with(strtolower($mime), 'image/') && strtolower($mime) !== 'image/svg+xml';

$response = new BinaryFileResponse($path);
$response->headers->set('Content-Type', $mime);
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->setContentDisposition(
    ($forceDownload || !$isImage)
        ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
        : ResponseHeaderBag::DISPOSITION_INLINE,
    $filename
);

return $response;
