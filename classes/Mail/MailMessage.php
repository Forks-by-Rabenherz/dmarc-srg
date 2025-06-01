<?php

/**
 * dmarc-srg - A php parser, viewer and summary report generator for incoming DMARC reports.
 * Copyright (C) 2020-2024 Aleksey Andreev (liuch)
 *
 * Available at:
 * https://github.com/liuch/dmarc-srg
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of  MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Liuch\DmarcSrg\Mail;

use Liuch\DmarcSrg\Core;
use Liuch\DmarcSrg\Exception\SoftException;
use Liuch\DmarcSrg\Exception\MailboxException;

class MailMessage
{
    private $conn;
    private $number;
    private $attachment;
    private $attachments_cnt;

    public function __construct($conn, $number)
    {
        $this->conn = $conn;
        $this->number = $number;
        $this->attachment = null;
        $this->attachments_cnt = -1;
    }

    public function overview()
    {
        $res = @imap_fetch_overview($this->conn, strval($this->number), FT_UID);
        if (!isset($res[0])) {
            if ($error_message = imap_last_error()) {
                Core::instance()->logger()->error("imap_fetch_overview failed: {$error_message}");
            }
            MailBox::resetErrorStack();
            return false;
        }
        return $res[0];
    }

    public function setSeen()
    {
        MailBox::resetErrorStack();
        @imap_setflag_full($this->conn, strval($this->number), '\\Seen', ST_UID);
        if (($error_message = imap_last_error())) {
            MailBox::resetErrorStack();
            Core::instance()->logger()->error("imap_setflag_full failed: {$error_message}");
            throw new MailboxException("Failed to make a message seen: {$error_message}");
        }
    }

    public function validate()
    {
        $this->ensureAttachment();
        if ($this->attachments_cnt !== 1) {
            throw new SoftException("Attachment count is not valid ({$this->attachments_cnt})");
        }

        $bytes = $this->attachment->size();
        if ($bytes === -1) {
            throw new SoftException("Failed to get attached file size. Wrong message format?");
        }
        if ($bytes < 50 || $bytes > 1 * 1024 * 1024) {
            throw new SoftException("Attachment file size is not valid ({$bytes} bytes)");
        }

        $mime_type = $this->attachment->mimeType();
        if (!in_array($mime_type, [ 'application/zip', 'application/gzip', 'application/x-gzip', 'text/xml' ])) {
            throw new SoftException("Attachment file type is not valid ({$mime_type})");
        }
    }

    public function attachment()
    {
        return $this->attachment;
    }

    private function ensureAttachment()
    {
        if ($this->attachments_cnt === -1) {
            $structure = imap_fetchstructure($this->conn, $this->number, FT_UID);
            if ($structure === false) {
                throw new MailboxException('FetchStructure failed: ' . imap_last_error());
            }
            $this->attachments_cnt = 0;
            $parts = isset($structure->parts) ? $structure->parts : [ $structure ];

            $allParts = [];
            foreach ($parts as $index => &$part) {
                $msgIndex = $index + 1;
                // when it's an entire attached message: MESSAGE/RFC822
                if (isset($part->parts) && count($part->parts) > 0) {
                    foreach ($part->parts as $subIndex => &$subPart) {
                        $allParts[$msgIndex . '.' . ($subIndex + 1)] = $subPart;
                    }
                    unset($subPart);// Remove the last dangling reference
                    continue;
                }
                $allParts[$msgIndex] = $part;
            }
            unset($part);// Remove the last dangling reference

            foreach ($allParts as $parNbr => &$part) {
                $att_part = $this->scanAttachmentPart($part, $parNbr);
                if ($att_part) {
                    ++$this->attachments_cnt;
                    if (!$this->attachment) {
                        $this->attachment = new MailAttachment($this->conn, $att_part);
                    }
                }
            }
            unset($part);// Remove the last dangling reference
        }
    }

    /**
     * @param string $parNbr
     * @return array|null
     */
    private function scanAttachmentPart(&$part, $parNbr)
    {
        $filename = null;
        if ($part->ifdparameters) {
            $filename = $this->getAttribute($part->dparameters, 'filename');
        }

        if (empty($filename) && $part->ifparameters) {
            $filename = $this->getAttribute($part->parameters, 'name');
        }

        if (empty($filename)) {
            return null;
        }

        return [
            'filename' => imap_utf8($filename),
            'bytes'    => isset($part->bytes) ? $part->bytes : -1,
            'number'   => $parNbr,
            'mnumber'  => $this->number,
            'encoding' => $part->encoding
        ];
    }

    private function getAttribute(&$params, $name)
    {
        // need to check all objects as imap_fetchstructure
        // returns multiple objects with the same attribute name,
        // but first entry contains a truncated value
        $value = null;
        foreach ($params as &$obj) {
            if (strcasecmp($obj->attribute, $name) === 0) {
                $value = $obj->value;
            }
        }
        return $value;
    }
}
