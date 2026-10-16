package com.eis.pdf;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.FileAlreadyExistsException;
import java.util.UUID;

final class GeneratedPdfStore {
    static Path save(Path requested, byte[] pdf, byte[] json, boolean editing) throws IOException {
        Path target = requested;
        for (;;) {
            Path metadata = target.resolveSibling(target.getFileName().toString().replaceFirst("(?i)\\.pdf$", "") + ".json");
            boolean pdfCreated = false;
            boolean jsonCreated = false;
            try {
                Files.createFile(target);
                pdfCreated = true;
                Files.createFile(metadata);
                jsonCreated = true;
                Files.write(target, pdf);
                Files.write(metadata, json);
                return target;
            } catch (FileAlreadyExistsException collision) {
                if (pdfCreated) Files.deleteIfExists(target);
                if (!editing) throw collision;
                String base = requested.getFileName().toString().replaceFirst("(?i)\\.pdf$", "");
                target = requested.resolveSibling(base + "-revision-" + UUID.randomUUID() + ".pdf");
            } catch (IOException failure) {
                if (jsonCreated) Files.deleteIfExists(metadata);
                if (pdfCreated) Files.deleteIfExists(target);
                throw failure;
            }
        }
    }
}
