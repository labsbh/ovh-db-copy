#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Ovh\Api;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @noinspection PhpUnhandledExceptionInspection */
(new SingleCommandApplication())
    ->setName('Import database OVH')
    ->setVersion('1.0.0')
    ->addArgument('service_from', InputArgument::REQUIRED, 'The source service')
    ->addArgument('service_to', InputArgument::REQUIRED, 'The destination service')
    ->addArgument('db_from', InputArgument::REQUIRED, 'The source database')
    ->addArgument('db_to', InputArgument::REQUIRED, 'The destination database')
    ->setCode(static function (InputInterface $input, OutputInterface $output): int {
        $ovh = new Api($_SERVER['OVH_APP_KEY'], $_SERVER['OVH_APP_SECRET'], 'ovh-eu', $_SERVER['OVH_CONSUMER_KEY']);
        $io  = new SymfonyStyle($input, $output);

        $io->title(sprintf('Import database "%s/%s" to "%s/%s', $input->getArgument('service_from'), $input->getArgument('db_from'), $input->getArgument('service_to'), $input->getArgument('db_to')));

        $io->text('Retrieve dump list');
        $dumps = $ovh->get(sprintf('/hosting/privateDatabase/%s/database/%s/dump', $input->getArgument('service_from'), $input->getArgument('db_from')));
        if (0 === \count($dumps)) {
            $io->error('Can‘t find dump to import');

            return Command::FAILURE;
        }

        $io->text(sprintf('Retrieve last dump "%s"', $dumps[0]));
        $lastDump = $ovh->get(sprintf('/hosting/privateDatabase/%s/database/%s/dump/%s', $input->getArgument('service_from'), $input->getArgument('db_from'), $dumps[0]));

        if (!isset($lastDump['url'])) {
            $io->error('Can‘t get dump url');

            return Command::FAILURE;
        }

        $io->text('Fetching last dump content');
        $content      = file_get_contents($lastDump['url']);
        $documentName = (new \DateTime())->format('YmdHis');

        $io->text('Create document');
        $document = $ovh->post('/me/document', [
            'name' => $documentName,
        ]);

        if (!isset($document['id'])) {
            $io->error('Can‘t find document id');

            return Command::FAILURE;
        }

        $io->text('Fetch document');
        $document = $ovh->get(sprintf('/me/document/%s', $document['id']));

        if (!isset($document['putUrl'])) {
            $io->error('Can‘t find document put url');

            return Command::FAILURE;
        }

        $io->text('Push data to document');
        $tmpFile = tmpfile();
        fwrite($tmpFile, $content);
        fseek($tmpFile, 0);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $document['putUrl']);
        curl_setopt($ch, CURLOPT_PUT, 1);
        curl_setopt($ch, CURLOPT_INFILE, $tmpFile);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($content));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $curl_response_res = curl_exec($ch);
        fclose($tmpFile);
        curl_close($ch);

        $io->text('Import document');
        $import = $ovh->post(sprintf('/hosting/privateDatabase/%s/database/%s/import', $input->getArgument('service_to'), $input->getArgument('db_to')), [
            'documentId'    => $document['id'],
            'flushDatabase' => true,
            'sendEmail'     => true,
        ]);

        $io->text('Fetch all documents');
        $documents = $ovh->get('/me/document');

        $io->text('Deleting other documents');
        foreach ($documents as $doc) {
            if ($doc !== $document['id']) {
                $ovh->delete(sprintf('/me/document/%s', $doc));
            }
        }

        $io->success('Database import with success');

        return Command::SUCCESS;
    })
    ->run();
