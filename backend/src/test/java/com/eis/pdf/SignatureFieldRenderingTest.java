package com.eis.pdf;

import com.google.gson.JsonArray;
import com.google.gson.JsonObject;
import java.lang.reflect.Method;
import java.nio.file.Paths;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.font.PDFont;
import org.apache.pdfbox.pdmodel.font.PDType0Font;
import org.apache.pdfbox.text.PDFTextStripper;
import org.junit.jupiter.api.Test;
import static org.junit.jupiter.api.Assertions.*;

class SignatureFieldRenderingTest {
    private JsonObject field() {
        JsonObject field = new JsonObject();
        field.addProperty("reviewAction", true);
        field.addProperty("signAction", true);
        field.addProperty("approveAction", false);
        field.addProperty("heightRatio", .03);
        field.addProperty("yRatio", .1);
        field.addProperty("signText", "ลงชื่อ");
        field.addProperty("signerName", "(นายทดสอบ นามสกุล)");
        field.addProperty("position", "หัวหน้าเจ้าหน้าที่");
        field.addProperty("faculty", "คณะแพทยศาสตร์");
        field.addProperty("actingForPresident", "ปฏิบัติการแทนอธิการบดี");
        field.addProperty("organization", "มหาวิทยาลัยนวมินทราธิราช");
        field.addProperty("signedDate", "10 ตุลาคม 2569");
        return field;
    }

    private String render(JsonObject field) throws Exception {
        try (PDDocument document = new PDDocument()) {
            document.addPage(new PDPage());
            PDFont font = PDType0Font.load(document,
                    Paths.get("src/main/webapp/lib/tcpdf/fonts/THSarabun.ttf").toFile());
            Method draw = PdfManagerServlet.class.getDeclaredMethod("drawJsonField",
                    PDDocument.class, PDFont.class, PDFont.class, JsonObject.class);
            draw.setAccessible(true);
            draw.invoke(new PdfManagerServlet(), document, font, font, field);
            return new PDFTextStripper().getText(document);
        }
    }

    @Test void shortBoxPreservesAllVisibleThaiRows() throws Exception {
        JsonObject field = field();
        String text = render(field);
        for (String key : new String[]{"signerName", "position", "faculty", "actingForPresident", "organization", "signedDate"}) {
            assertTrue(text.contains(field.get(key).getAsString()), key + ": " + text);
        }
    }

    @Test void approvalWithoutSignatureStillShowsNameAndDetails() throws Exception {
        JsonObject field = field();
        field.addProperty("signAction", false);
        field.addProperty("approveAction", true);
        field.addProperty("approval", "อนุมัติ");
        String text = render(field);
        assertTrue(text.contains("อนุมัติ"));
        assertTrue(text.contains("นายทดสอบ นามสกุล"));
        assertTrue(text.contains("มหาวิทยาลัยนวมินทราธิราช"));
    }

    @Test void nestedApprovalActionIsAlsoVisible() throws Exception {
        JsonObject field = field();
        field.remove("approveAction");
        field.addProperty("signAction", false);
        JsonObject actions = new JsonObject();
        actions.addProperty("approve", true);
        field.add("actions", actions);
        assertTrue(render(field).contains("นายทดสอบ นามสกุล"));
    }

    @Test void reviewOnlyBoxRemainsInvisible() throws Exception {
        JsonObject field = field();
        field.addProperty("signAction", false);
        assertTrue(render(field).trim().isEmpty());
    }

    @Test void hiddenRowsAndOmittedDateRemainInvisible() throws Exception {
        JsonObject field = field();
        JsonArray hidden = new JsonArray();
        hidden.add("faculty");
        field.add("hiddenRows", hidden);
        field.addProperty("signDateMode", "none");
        String text = render(field);
        assertTrue(text.contains("นายทดสอบ นามสกุล"));
        assertFalse(text.contains("คณะแพทยศาสตร์"));
        assertFalse(text.contains("10 ตุลาคม 2569"));
    }
}
