package com.eis.pdf;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import java.nio.file.Path;
import java.nio.file.Files;
import java.nio.file.FileAlreadyExistsException;
import static org.junit.jupiter.api.Assertions.*;

class GeneratedPdfStoreTest {
    @TempDir Path directory;
    @Test void duplicateNewDocumentPreservesOriginal() throws Exception {
        Path pdf = directory.resolve("เอกสาร.pdf");
        Files.writeString(pdf, "original");
        assertThrows(FileAlreadyExistsException.class, () -> GeneratedPdfStore.save(pdf, "new".getBytes(), "{}".getBytes(), false));
        assertEquals("original", Files.readString(pdf));
    }
    @Test void editCreatesRevisionAndPreservesOriginalPair() throws Exception {
        Path pdf = directory.resolve("เอกสาร.pdf");
        Files.writeString(pdf, "original");
        Files.writeString(directory.resolve("เอกสาร.json"), "original metadata");
        Path revision = GeneratedPdfStore.save(pdf, "updated".getBytes(), "{}".getBytes(), true);
        assertNotEquals(pdf, revision);
        assertEquals("updated", Files.readString(revision));
        assertEquals("original", Files.readString(pdf));
        assertEquals("original metadata", Files.readString(directory.resolve("เอกสาร.json")));
    }
    @Test void metadataCollisionDoesNotLeavePdfOrOverwriteMetadata() throws Exception {
        Path pdf = directory.resolve("document.pdf");
        Files.writeString(directory.resolve("document.json"), "original");
        assertThrows(FileAlreadyExistsException.class, () -> GeneratedPdfStore.save(pdf, "new".getBytes(), "{}".getBytes(), false));
        assertFalse(Files.exists(pdf));
        assertEquals("original", Files.readString(directory.resolve("document.json")));
    }
}
