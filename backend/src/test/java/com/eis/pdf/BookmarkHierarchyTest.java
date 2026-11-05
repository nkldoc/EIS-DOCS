package com.eis.pdf;

import com.google.gson.JsonParser;
import java.lang.reflect.Method;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.interactive.documentnavigation.outline.PDOutlineItem;
import org.junit.jupiter.api.Test;
import static org.junit.jupiter.api.Assertions.*;

class BookmarkHierarchyTest {
    @Test
    void preservesChildrenAndTheirDestinations() throws Exception {
        try (PDDocument document = new PDDocument()) {
            document.addPage(new PDPage());
            document.addPage(new PDPage());
            Method add = PdfManagerServlet.class.getDeclaredMethod("addBookmarks", PDDocument.class, com.google.gson.JsonArray.class);
            add.setAccessible(true);
            add.invoke(new PdfManagerServlet(), document, JsonParser.parseString("["
                + "{\"name\":\"Parent\",\"page\":1,\"level\":0},"
                + "{\"name\":\"Child\",\"page\":2,\"level\":1},"
                + "{\"name\":\"Next\",\"page\":1,\"level\":0}]").getAsJsonArray());
            PDOutlineItem first = document.getDocumentCatalog().getDocumentOutline().getFirstChild();
            assertEquals("Parent", first.getTitle());
            assertEquals("Child", first.getFirstChild().getTitle());
            assertSame(document.getPage(1).getCOSObject(), first.getFirstChild().findDestinationPage(document).getCOSObject());
            assertEquals("Next", first.getNextSibling().getTitle());
            assertNull(first.getFirstChild().getNextSibling());
        }
    }
}
