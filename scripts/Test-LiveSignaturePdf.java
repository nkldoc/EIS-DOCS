import com.google.gson.*;
import java.io.*;
import java.net.URI;
import java.net.http.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import javax.imageio.ImageIO;
import org.apache.pdfbox.pdmodel.*;
import org.apache.pdfbox.rendering.PDFRenderer;
import org.apache.pdfbox.text.PDFTextStripper;

class TestLiveSignaturePdf {
    public static void main(String[] args) throws Exception {
        Path qa = Paths.get("D:/EIS-DOCS/storage/qa/signature-fields");
        Files.createDirectories(qa);
        byte[] source;
        try (PDDocument blank = new PDDocument(); ByteArrayOutputStream bytes = new ByteArrayOutputStream()) {
            blank.addPage(new PDPage()); blank.save(bytes); source = bytes.toByteArray();
        }
        JsonObject payload = new JsonObject();
        payload.addProperty("fileName", "qa/signature-fields-" + System.currentTimeMillis());
        JsonArray fields = new JsonArray();
        String[] names = {"(นายทดสอบ ลงนาม)", "(นายทดสอบ อนุมัติ)", "ชื่อผู้ตรวจสอบเท่านั้น"};
        for (int i = 0; i < 3; i++) {
            JsonObject field = new JsonObject();
            field.addProperty("page", 1); field.addProperty("xRatio", .15);
            field.addProperty("yRatio", .10 + i * .25);
            field.addProperty("widthRatio", .55); field.addProperty("heightRatio", .03);
            field.addProperty("fontSize", 16);
            field.addProperty("reviewAction", true);
            field.addProperty("signAction", i == 0);
            field.addProperty("approveAction", i == 1);
            if (i == 0) field.addProperty("signText", "ลงชื่อ");
            if (i == 1) field.addProperty("approval", "อนุมัติ");
            field.addProperty("signerName", names[i]);
            field.addProperty("position", "หัวหน้าเจ้าหน้าที่");
            field.addProperty("faculty", "คณะแพทยศาสตร์");
            field.addProperty("organization", "มหาวิทยาลัยนวมินทราธิราช");
            field.addProperty("signedDate", "10 ตุลาคม 2569");
            fields.add(field);
        }
        payload.add("signatureFields", fields);
        String boundary = "SignatureFieldsQaBoundary";
        ByteArrayOutputStream requestBody = new ByteArrayOutputStream();
        requestBody.write(("--" + boundary + "\r\nContent-Disposition: form-data; name=\"payload\"\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" + payload + "\r\n").getBytes(StandardCharsets.UTF_8));
        requestBody.write(("--" + boundary + "\r\nContent-Disposition: form-data; name=\"pdfFile\"; filename=\"blank.pdf\"\r\nContent-Type: application/pdf\r\n\r\n").getBytes(StandardCharsets.UTF_8));
        requestBody.write(source);
        requestBody.write(("\r\n--" + boundary + "--\r\n").getBytes(StandardCharsets.UTF_8));
        HttpRequest request = HttpRequest.newBuilder(URI.create("http://127.0.0.1:8080/eis-docs/api/pdf/generate"))
                .header("Content-Type", "multipart/form-data; boundary=" + boundary)
                .POST(HttpRequest.BodyPublishers.ofByteArray(requestBody.toByteArray())).build();
        HttpResponse<byte[]> response = HttpClient.newHttpClient().send(request, HttpResponse.BodyHandlers.ofByteArray());
        if (response.statusCode() != 200) throw new AssertionError("HTTP " + response.statusCode() + ": " + new String(response.body(), StandardCharsets.UTF_8));
        Files.write(qa.resolve("live-generated.pdf"), response.body());
        try (PDDocument pdf = PDDocument.load(response.body())) {
            String text = new PDFTextStripper().getText(pdf);
            for (String expected : new String[]{names[0], names[1], "หัวหน้าเจ้าหน้าที่", "คณะแพทยศาสตร์", "มหาวิทยาลัยนวมินทราธิราช", "10 ตุลาคม 2569"}) {
                if (!text.contains(expected)) throw new AssertionError("Missing: " + expected);
            }
            if (text.contains(names[2])) throw new AssertionError("Review-only field became visible");
            ImageIO.write(new PDFRenderer(pdf).renderImageWithDPI(0, 120), "png", qa.resolve("live-generated.png").toFile());
        }
        System.out.println("PASS: Live API PDF contains Thai names and all rows; review-only field remains hidden.");
        System.out.println("PDF: " + qa.resolve("live-generated.pdf"));
    }
}
