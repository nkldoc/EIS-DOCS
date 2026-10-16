package com.eis.pdf;

import javax.servlet.ServletException;
import javax.servlet.annotation.MultipartConfig;
import javax.servlet.annotation.WebServlet;
import javax.servlet.http.*;
import java.io.*;
import java.awt.Color;
import java.awt.image.BufferedImage;
import java.util.*;
import java.util.stream.Collectors;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.StandardCopyOption;
import javax.imageio.ImageIO;

import com.google.gson.*;

import org.apache.pdfbox.pdmodel.*;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDFont;
import org.apache.pdfbox.pdmodel.font.PDType0Font;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.apache.pdfbox.pdmodel.graphics.image.LosslessFactory;
import org.apache.pdfbox.pdmodel.graphics.state.PDExtendedGraphicsState;
import org.apache.pdfbox.util.Matrix;
import org.apache.pdfbox.pdmodel.interactive.documentnavigation.destination.*;
import org.apache.pdfbox.pdmodel.interactive.documentnavigation.outline.*;

@WebServlet(urlPatterns = {"/pdf/*"})
@MultipartConfig
public class PdfManagerServlet extends HttpServlet {

    // ตรวจสอบให้แน่ใจว่าพาธลงท้ายด้วยสแลช (/) เพื่อป้องกันชื่อไฟล์เพี้ยน
    private static final String UPLOAD_DIR = "D:/Documents/Sys/2025/procure/supplies/uploads/";
    private static final String TEMPLATE_NAME = "currentTemplate.pdf";

    @Override
    public void init() throws ServletException {
        new File(UPLOAD_DIR).mkdirs();
    }

    @Override
    protected void doOptions(HttpServletRequest req, HttpServletResponse resp) {
        addCorsHeaders(resp);
        resp.setStatus(HttpServletResponse.SC_NO_CONTENT);
    }

    @Override
    protected void doGet(HttpServletRequest req, HttpServletResponse resp)
            throws IOException {
        addCorsHeaders(resp);
        String path = req.getPathInfo();
        if (path == null) {
            path = "";
        }
        switch (path) {
            case "/bookmarks":
                handleListBookmarks(resp);
                break;
            case "/serverFiles":
                handleServerFiles(resp);
                break;
            case "/template":
                handleServeTemplate(resp);
                break;
            case "/fields":
                handleGetFields(req, resp);
                break;
            default:
                resp.sendError(404, "Not Found");
        }
    }

    @Override
    protected void doPost(HttpServletRequest req, HttpServletResponse resp)
            throws IOException, ServletException {
        addCorsHeaders(resp);
        String path = req.getPathInfo();
        if (path == null) {
            path = "";
        }
        switch (path) {
            case "/uploadTemplate":
                handleUploadTemplate(req, resp);
                break;
            case "/edit":
                handleEdit(req, resp);
                break;
            case "/deletePages":
                deletePages(req, resp);
                break;
            case "/generate":
                handleGeneratePdf(req, resp);
                break;
            case "/sign":
                handleSignByUserId(req, resp);
                break;
            default:
                resp.sendError(404, "Not Found");
        }
    }

    // ---------- Sign exactly the signature field(s) assigned to user_id ----------
    private void handleSignByUserId(HttpServletRequest req, HttpServletResponse resp)
            throws IOException, ServletException {
        String docId = trim(readTextPart(req, "docId"));
        String userId = trim(readTextPart(req, "user_id"));
        String fieldsText = trim(readTextPart(req, "signatureFields"));
        Part signaturePart = req.getPart("signatureImage");

        if (docId.isEmpty() || userId.isEmpty()) {
            writeJsonError(resp, 400, "กรุณาระบุ docId และ user_id");
            return;
        }
        if (!userId.matches("\\d+")) {
            writeJsonError(resp, 400, "user_id ต้องเป็นตัวเลข");
            return;
        }
        if (fieldsText.isEmpty()) {
            writeJsonError(resp, 400, "ไม่พบ signatureFields สำหรับตรวจสอบสิทธิ์ผู้ลงนาม");
            return;
        }
        if (signaturePart == null || signaturePart.getSize() == 0) {
            writeJsonError(resp, 400, "ไม่พบไฟล์ลายเซ็น");
            return;
        }
        if (signaturePart.getSize() > 5 * 1024 * 1024) {
            writeJsonError(resp, 400, "ไฟล์ลายเซ็นต้องมีขนาดไม่เกิน 5 MB");
            return;
        }

        JsonArray fields;
        try {
            fields = JsonParser.parseString(fieldsText).getAsJsonArray();
        } catch (Exception e) {
            writeJsonError(resp, 400, "signatureFields ไม่ใช่ JSON array ที่ถูกต้อง");
            return;
        }

        JsonArray assignedFields = new JsonArray();
        for (JsonElement element : fields) {
            if (element == null || !element.isJsonObject()) {
                continue;
            }
            JsonObject field = element.getAsJsonObject();
            String assignedUserId = jsonString(field, "user_id", "assigneeId").trim();
            if (userId.equals(assignedUserId)) {
                assignedFields.add(field.deepCopy());
            }
        }
        if (assignedFields.size() == 0) {
            writeJsonError(resp, 403, "user_id " + userId + " ไม่มีช่องลงนามในเอกสารนี้");
            return;
        }

        File documentFile;
        try {
            documentFile = resolveDocumentFile(docId);
        } catch (IOException e) {
            writeJsonError(resp, 400, e.getMessage());
            return;
        }
        boolean createdDocument = false;
        if (!documentFile.isFile()) {
            File sourceTemplate = new File(UPLOAD_DIR, TEMPLATE_NAME);
            if (!sourceTemplate.isFile()) {
                writeJsonError(resp, 404, "ไม่พบเอกสาร " + docId + " และยังไม่มี currentTemplate.pdf สำหรับสร้างเอกสารใหม่");
                return;
            }
            File parent = documentFile.getParentFile();
            if (!parent.isDirectory() && !parent.mkdirs()) {
                writeJsonError(resp, 500, "สร้างโฟลเดอร์สำหรับเอกสารไม่สำเร็จ");
                return;
            }
            try {
                Files.copy(sourceTemplate.toPath(), documentFile.toPath());
                createdDocument = true;
            } catch (java.nio.file.FileAlreadyExistsException ignored) {
                // มีคำขอลงนามอีกคำขอสร้างไฟล์เดียวกันพร้อมกัน ให้ใช้ไฟล์ที่สร้างแล้วต่อได้
            } catch (IOException e) {
                writeJsonError(resp, 500, "สร้างเอกสารจาก Template ไม่สำเร็จ: " + e.getMessage());
                return;
            }
        }

        byte[] signatureBytes;
        try (InputStream in = signaturePart.getInputStream()) {
            signatureBytes = readAllBytes(in, 5 * 1024 * 1024);
        }
        BufferedImage signatureImage = ImageIO.read(new ByteArrayInputStream(signatureBytes));
        if (signatureImage == null) {
            writeJsonError(resp, 400, "ไฟล์ลายเซ็นต้องเป็นรูปภาพ PNG หรือ JPEG");
            return;
        }
        ByteArrayOutputStream pngOutput = new ByteArrayOutputStream();
        ImageIO.write(signatureImage, "png", pngOutput);
        String imageData = "data:image/png;base64," + Base64.getEncoder().encodeToString(pngOutput.toByteArray());
        String signerName = trim(readTextPart(req, "signerName"));
        String position = trim(readTextPart(req, "position"));
        String organization = trim(readTextPart(req, "organization"));
        String signedDate = trim(readTextPart(req, "signedDate"));

        File tempFile = File.createTempFile("sign-" + userId + "-", ".pdf", documentFile.getParentFile());
        try (PDDocument document = PDDocument.load(documentFile);
                InputStream fontStream = getServletContext().getResourceAsStream("/lib/tcpdf/fonts/THSarabun.ttf");
                InputStream boldFontStream = getServletContext().getResourceAsStream("/lib/tcpdf/fonts/THSarabun Bold.ttf")) {
            if (fontStream == null || boldFontStream == null) {
                throw new IOException("ไม่พบฟอนต์ THSarabun");
            }
            PDFont font = PDType0Font.load(document, fontStream, true);
            PDFont boldFont = PDType0Font.load(document, boldFontStream, true);
            for (JsonElement element : assignedFields) {
                JsonObject field = element.getAsJsonObject();
                field.addProperty("signatureImage", imageData);
                if (!signerName.isEmpty()) field.addProperty("signerName", signerName);
                if (!position.isEmpty()) field.addProperty("position", position);
                if (!organization.isEmpty()) field.addProperty("organization", organization);
                if (!signedDate.isEmpty()) field.addProperty("signedDate", signedDate);
                drawJsonField(document, font, boldFont, field);
            }
            document.save(tempFile);
        } catch (Exception e) {
            tempFile.delete();
            writeJsonError(resp, 500, "ลงนามไม่สำเร็จ: " + e.getMessage());
            return;
        }
        try {
            Files.move(tempFile.toPath(), documentFile.toPath(), StandardCopyOption.REPLACE_EXISTING);
        } catch (IOException e) {
            tempFile.delete();
            writeJsonError(resp, 500, "บันทึกเอกสารที่ลงนามไม่สำเร็จ: " + e.getMessage());
            return;
        }

        Map<String, Object> result = new LinkedHashMap<>();
        result.put("success", true);
        result.put("message", "ลงนามสำเร็จ");
        result.put("docId", docId);
        result.put("user_id", userId);
        result.put("appliedFields", assignedFields.size());
        result.put("createdDocument", createdDocument);
        result.put("filePath", documentFile.getAbsolutePath());
        writeJson(resp, 200, result);
    }

    // อักขระที่ Windows ห้ามใช้ในชื่อไฟล์/โฟลเดอร์: \ : * ? " < > |
    // (ยังอนุญาตให้ใช้ / เป็นตัวคั่นโฟลเดอร์ย่อยของ docId เช่น procure/2026/PO-00123)
    private static final java.util.regex.Pattern INVALID_DOCID_CHARS =
            java.util.regex.Pattern.compile("[\\\\:*?\"<>|]");

    private File resolveDocumentFile(String docId) throws IOException {
        if (docId == null || docId.trim().isEmpty()) {
            throw new IOException("docId ไม่ถูกต้อง (ว่างเปล่า)");
        }
        docId = docId.trim();
        if (INVALID_DOCID_CHARS.matcher(docId).find()) {
            throw new IOException("docId มีอักขระที่ไม่อนุญาต (ห้ามใช้ \\ : * ? \" < > |) — ค่าที่ได้รับ: " + docId);
        }
        if (docId.contains("..")) {
            throw new IOException("docId ไม่ถูกต้อง");
        }
        String normalizedName = docId.toLowerCase(Locale.ROOT).endsWith(".pdf") ? docId : docId + ".pdf";

        File root = new File(UPLOAD_DIR).getCanonicalFile();
        File file;
        try {
            file = new File(root, normalizedName).getCanonicalFile();
        } catch (IOException e) {
            // ครอบ error ดิบของ Windows ("The filename, directory name, or volume
            // label syntax is incorrect") ให้เป็นข้อความที่บอกสาเหตุจริงแทน
            throw new IOException("docId มีรูปแบบไม่ถูกต้องสำหรับระบบไฟล์: " + docId);
        }
        String rootPath = root.getPath() + File.separator;
        if (!file.getPath().startsWith(rootPath) || !file.getName().toLowerCase(Locale.ROOT).endsWith(".pdf")) {
            throw new IOException("docId ไม่ถูกต้อง");
        }
        return file;
    }

    private byte[] readAllBytes(InputStream in, int maxBytes) throws IOException {
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        byte[] buffer = new byte[8192];
        int total = 0;
        int count;
        while ((count = in.read(buffer)) != -1) {
            total += count;
            if (total > maxBytes) throw new IOException("ไฟล์มีขนาดใหญ่เกินกำหนด");
            out.write(buffer, 0, count);
        }
        return out.toByteArray();
    }

    private String trim(String value) {
        return value == null ? "" : value.trim();
    }

    // อ่านค่า field ธรรมดา (ไม่ใช่ไฟล์) จาก multipart/form-data เป็น UTF-8 เอง
    // เพราะ req.getParameter() กับ multipart ให้ container ถอดรหัสเป็น ISO-8859-1 โดยดีฟอลต์
    // ทำให้ข้อความไทยที่ส่งมาจาก signature-pad.html/layout.html (เช่น signerName, position, signatureFields) เพี้ยน
    private String readTextPart(HttpServletRequest req, String name) throws IOException, ServletException {
        Part part = req.getPart(name);
        if (part == null) {
            return null;
        }
        try (Reader reader = new InputStreamReader(part.getInputStream(), StandardCharsets.UTF_8)) {
            StringBuilder sb = new StringBuilder();
            char[] buf = new char[4096];
            int n;
            while ((n = reader.read(buf)) != -1) {
                sb.append(buf, 0, n);
            }
            return sb.toString();
        }
    }

    private void addCorsHeaders(HttpServletResponse resp) {
        // รองรับหน้า layout/signature-pad ที่เปิดจาก file:// ระหว่างพัฒนา
        resp.setHeader("Access-Control-Allow-Origin", "*");
        resp.setHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
        resp.setHeader("Access-Control-Allow-Headers", "Content-Type, Accept");
        // เปิดให้ JS ฝั่ง browser อ่าน header ที่บอกพาธปลายทางที่บันทึกไฟล์ไว้ได้
        resp.setHeader("Access-Control-Expose-Headers", "X-Saved-Pdf-Path, X-Saved-Json-Path, X-Doc-Id");
    }

    // ---------- Generate PDF from the JSON currently shown in the layout UI ----------
    // รองรับ 2 แบบ: (1) multipart/form-data ที่แนบไฟล์ PDF ("pdfFile") + JSON ("payload") มาด้วย — ใช้จาก layout.html
    //              (2) application/json ธรรมดา (ของเดิม) — ใช้ PDF ที่เคยอัปโหลดไว้ที่ UPLOAD_DIR/TEMPLATE_NAME
    private void handleGeneratePdf(HttpServletRequest req, HttpServletResponse resp)
            throws IOException, ServletException {
        boolean isMultipart = req.getContentType() != null
                && req.getContentType().toLowerCase(Locale.ROOT).startsWith("multipart/");

        Part pdfPart = null;
        JsonObject payload;
        if (isMultipart) {
            try {
                pdfPart = req.getPart("pdfFile");
            } catch (Exception ignored) {
            }

            // สำคัญ: ห้ามใช้ req.getParameter("payload") กับ multipart form-data
            // เพราะ container จะถอดรหัส field เป็น ISO-8859-1 โดยดีฟอลต์ ทำให้ข้อความไทย (UTF-8)
            // กลายเป็นอักขระขยะ (เช่น U+0087) จนฟอนต์หา glyph ไม่เจอ — อ่านจาก Part.getInputStream()
            // แล้วถอดรหัสเป็น UTF-8 เองแทน เพื่อไม่พึ่งค่าดีฟอลต์ของ container
            String payloadText = null;
            Part payloadPart = req.getPart("payload");
            if (payloadPart != null) {
                try (Reader reader = new InputStreamReader(payloadPart.getInputStream(), StandardCharsets.UTF_8)) {
                    StringBuilder sb = new StringBuilder();
                    char[] buf = new char[4096];
                    int n;
                    while ((n = reader.read(buf)) != -1) {
                        sb.append(buf, 0, n);
                    }
                    payloadText = sb.toString();
                }
            }
            if (payloadText == null || payloadText.trim().isEmpty()) {
                writeJsonError(resp, 400, "ไม่พบข้อมูล payload");
                return;
            }
            try {
                payload = JsonParser.parseString(payloadText).getAsJsonObject();
            } catch (Exception e) {
                writeJsonError(resp, 400, "JSON ไม่ถูกต้อง: " + e.getMessage());
                return;
            }
        } else {
            try (Reader reader = new InputStreamReader(req.getInputStream(), "UTF-8")) {
                payload = JsonParser.parseReader(reader).getAsJsonObject();
            } catch (Exception e) {
                writeJsonError(resp, 400, "JSON ไม่ถูกต้อง: " + e.getMessage());
                return;
            }
        }

        JsonArray fields = jsonArray(payload, "signatureFields", "involvedPartyFields", "fields", "boxes");
        if (fields == null) {
            writeJsonError(resp, 400, "ไม่พบ signatureFields ใน JSON");
            return;
        }

        String requestedName = jsonString(payload, "fileName", "docId");
        String downloadName = sanitizeDownloadName(requestedName);
        if (downloadName.isEmpty()) {
            downloadName = "saved-document";
        }
        if (!downloadName.toLowerCase(Locale.ROOT).endsWith(".pdf")) {
            downloadName += ".pdf";
        }

        boolean hasUploadedPdf = pdfPart != null && pdfPart.getSize() > 0;
        File savedPdf = new File(UPLOAD_DIR + TEMPLATE_NAME);
        if (!hasUploadedPdf && !savedPdf.isFile()) {
            writeJsonError(resp, 400, "ไม่พบไฟล์ PDF ต้นฉบับ กรุณาอัปโหลดไฟล์ PDF ก่อน");
            return;
        }

        byte[] generatedPdf;
        try (PDDocument document = hasUploadedPdf
                ? PDDocument.load(pdfPart.getInputStream())
                : PDDocument.load(savedPdf); InputStream fontStream = getServletContext().getResourceAsStream(
                "/lib/tcpdf/fonts/THSarabun.ttf"); InputStream boldFontStream = getServletContext().getResourceAsStream(
                        "/lib/tcpdf/fonts/THSarabun Bold.ttf"); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            if (fontStream == null || boldFontStream == null) {
                throw new IOException("ไม่พบฟอนต์ THSarabun");
            }
            PDFont font = PDType0Font.load(document, fontStream, true);
            PDFont boldFont = PDType0Font.load(document, boldFontStream, true);
            // ฝัง (bake) การหมุนหน้าลงในเนื้อหาจริง แล้วเคลียร์ /Rotate เป็น 0
            // เพื่อให้ viewer/renderer ใดก็ตามแสดงผลถูกทาง แม้ตัวที่ไม่รองรับ /Rotate
            for (PDPage p : document.getPages()) {
                normalizePageRotation(document, p);
            }
            for (JsonElement element : fields) {
                if (element != null && element.isJsonObject()) {
                    drawJsonField(document, font, boldFont, element.getAsJsonObject());
                }
            }
            addBookmarks(document, payload.getAsJsonArray("bookmarks"));
            document.save(output);
            generatedPdf = output.toByteArray();
        } catch (Exception e) {
            writeJsonError(resp, 500, "สร้าง PDF ไม่สำเร็จ: " + e.getMessage());
            return;
        }

        resp.setContentType("application/pdf");
        resp.setContentLength(generatedPdf.length);
        resp.setHeader("Cache-Control", "no-store");
        resp.setHeader("Content-Disposition", "attachment; filename=\"" + downloadName + "\"");

        // เก็บสำเนาไฟล์ที่สร้างไว้บนเซิร์ฟเวอร์โดยใช้ docId เป็นชื่อไฟล์/พาธ
        // เพื่อให้ /pdf/sign หาไฟล์เจอในภายหลังตอนแต่ละคนมาลงนามจริง (resolveDocumentFile ใช้ docId เดียวกันนี้)
        // พร้อมทั้งบันทึก payload (signatureFields ฯลฯ) เป็นไฟล์ .json คู่กันไว้ในโฟลเดอร์เดียวกัน
        // เพื่อให้ signature-pad.html (ซึ่งอาจเปิดจากคนละเครื่อง/เบราว์เซอร์) ดึงมาใช้ตรวจสอบสิทธิ์ผู้ลงนามได้
        // ผ่าน GET /pdf/fields?docId=... แทนที่จะพึ่งพา localStorage อย่างเดียว
        String rawDocId = requestedName;
        if (!rawDocId.isEmpty()) {
            try {
                File serverCopy = resolveDocumentFile(rawDocId);
                File parent = serverCopy.getParentFile();
                if (parent != null) {
                    parent.mkdirs();
                }
                Files.write(serverCopy.toPath(), generatedPdf);

                File jsonCopy = resolveJsonFile(rawDocId);
                Files.write(jsonCopy.toPath(), payload.toString().getBytes(StandardCharsets.UTF_8));

                resp.setHeader("X-Doc-Id", urlEncodeHeader(rawDocId));
                resp.setHeader("X-Saved-Pdf-Path", urlEncodeHeader(serverCopy.getAbsolutePath()));
                resp.setHeader("X-Saved-Json-Path", urlEncodeHeader(jsonCopy.getAbsolutePath()));
            } catch (IOException e) {
                // docId ไม่ผ่านการตรวจสอบ path หรือเขียนไฟล์ไม่สำเร็จ — ไม่ทำให้การดาวน์โหลดล้มเหลว
                // แต่ /pdf/sign จะหาเอกสารนี้ไม่เจอในภายหลัง จึง log ไว้เพื่อ debug
                getServletContext().log("บันทึกสำเนาเอกสารสำหรับ docId '" + rawDocId + "' ไม่สำเร็จ: " + e.getMessage());
            }
        }

        resp.getOutputStream().write(generatedPdf);
    }

    // ---------- ดึง signatureFields ที่บันทึกไว้คู่กับ docId กลับไปให้ signature-pad.html ----------
    private void handleGetFields(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        String docId = req.getParameter("docId");
        if (docId == null || docId.trim().isEmpty()) {
            writeJsonError(resp, 400, "กรุณาระบุ docId");
            return;
        }
        File jsonFile;
        try {
            jsonFile = resolveJsonFile(docId.trim());
        } catch (IOException e) {
            writeJsonError(resp, 400, e.getMessage());
            return;
        }
        if (!jsonFile.isFile()) {
            writeJsonError(resp, 404, "ไม่พบข้อมูล signatureFields สำหรับ docId " + docId);
            return;
        }
        String content = new String(Files.readAllBytes(jsonFile.toPath()), StandardCharsets.UTF_8);
        resp.setContentType("application/json; charset=UTF-8");
        resp.setHeader("Cache-Control", "no-store");
        resp.getWriter().write(content);
    }

    // ---------- พาธของไฟล์ .json ที่เก็บคู่กับเอกสาร docId (ใช้กฎ path เดียวกับ resolveDocumentFile) ----------
    private File resolveJsonFile(String docId) throws IOException {
        File pdfFile = resolveDocumentFile(docId);
        String name = pdfFile.getName();
        String baseName = name.toLowerCase(Locale.ROOT).endsWith(".pdf")
                ? name.substring(0, name.length() - 4)
                : name;
        return new File(pdfFile.getParentFile(), baseName + ".json");
    }

    private String urlEncodeHeader(String value) {
        try {
            return java.net.URLEncoder.encode(value, "UTF-8");
        } catch (IOException e) {
            return value.replaceAll("[^A-Za-z0-9._-]", "_");
        }
    }

    /**
     * Appends
     * the
     * bookmarks
     * supplied
     * by
     * layout.html
     * to
     * the
     * PDF
     * outline.
     * Payload
     * page
     * numbers
     * are
     * one-based,
     * while
     * PDFBox
     * page
     * indexes
     * are
     * zero-based.
     */
    private void addBookmarks(PDDocument document, JsonArray bookmarks) throws IOException {
        if (bookmarks == null || bookmarks.size() == 0) {
            return;
        }

        PDDocumentOutline outline = document.getDocumentCatalog().getDocumentOutline();
        if (outline == null) {
            outline = new PDDocumentOutline();
            document.getDocumentCatalog().setDocumentOutline(outline);
        }

        int pageCount = document.getNumberOfPages();
        for (JsonElement element : bookmarks) {
            if (element == null || !element.isJsonObject()) {
                continue;
            }

            JsonObject bookmark = element.getAsJsonObject();
            String title = jsonString(bookmark, "name", "title");
            if (title == null || title.trim().isEmpty()) {
                continue;
            }

            int pageNumber;
            try {
                pageNumber = bookmark.has("page") ? bookmark.get("page").getAsInt() : 0;
            } catch (Exception ignored) {
                pageNumber = 0;
            }
            if (pageNumber < 1 || pageNumber > pageCount) {
                throw new IOException("Bookmark '" + title + "' refers to page " + pageNumber
                        + ", but the PDF has " + pageCount + " page(s)");
            }

            PDPageFitDestination destination = new PDPageFitDestination();
            destination.setPage(document.getPage(pageNumber - 1));

            PDOutlineItem item = new PDOutlineItem();
            item.setTitle(title.trim());
            item.setDestination(destination);
            outline.addLast(item);
        }
        outline.openNode();
    }

    // ฝังการหมุนหน้า (จาก /Rotate metadata) ลงในเนื้อหาจริงของหน้า แล้วเซ็ต rotation กลับเป็น 0
    // ป้องกันปัญหาแสดงกลับหัว/เอียงในวิวเวอร์หรือกระบวนการแปลงไฟล์ที่ไม่อ่านค่า /Rotate
    private void normalizePageRotation(PDDocument document, PDPage page) throws IOException {
        int rotation = ((page.getRotation() % 360) + 360) % 360;
        if (rotation == 0) {
            return;
        }

        PDRectangle box = page.getMediaBox();
        float rawWidth = box.getWidth();
        float rawHeight = box.getHeight();

        Matrix bakeMatrix;
        PDRectangle newBox;
        switch (rotation) {
            case 90:
                bakeMatrix = new Matrix(0, 1, -1, 0, rawHeight, 0);
                newBox = new PDRectangle(rawHeight, rawWidth);
                break;
            case 180:
                bakeMatrix = new Matrix(-1, 0, 0, -1, rawWidth, rawHeight);
                newBox = new PDRectangle(rawWidth, rawHeight);
                break;
            case 270:
                bakeMatrix = new Matrix(0, -1, 1, 0, 0, rawWidth);
                newBox = new PDRectangle(rawHeight, rawWidth);
                break;
            default:
                return; // ไม่ใช่มุม 90 องศา ข้ามไป (ไม่ควรเกิดตามสเปก PDF)
        }

        // PREPEND: แทรก cm (transform) ไว้ก่อนเนื้อหาเดิมทั้งหมด เพื่อ "หมุน" เนื้อหาดิบ
        // ให้ตรงกับที่ viewer ซึ่ง honor /Rotate เคยแสดงผล
        try (PDPageContentStream content = new PDPageContentStream(
                document, page, PDPageContentStream.AppendMode.PREPEND, false, true)) {
            content.transform(bakeMatrix);
        }

        page.setMediaBox(newBox);
        page.setCropBox(newBox);
        page.setRotation(0);
    }

    private void drawJsonField(PDDocument document, PDFont font, PDFont boldFont,
            JsonObject field) throws IOException {
        int pageNumber = jsonInt(field, "page", 1);
        if (pageNumber < 1 || pageNumber > document.getNumberOfPages()) {
            return;
        }

        PDPage page = document.getPage(pageNumber - 1);
        PDRectangle box = page.getCropBox();
        float rawWidth = box.getWidth();
        float rawHeight = box.getHeight();
        int rotation = ((page.getRotation() % 360) + 360) % 360;
        float pageWidth = rotation == 90 || rotation == 270 ? rawHeight : rawWidth;
        float pageHeight = rotation == 90 || rotation == 270 ? rawWidth : rawHeight;
        float x = (float) (jsonDouble(field, "xRatio", 0) * pageWidth);
        float width = Math.max(20, (float) (jsonDouble(field, "widthRatio", .4) * pageWidth));
        float height = Math.max(20, (float) (jsonDouble(field, "heightRatio", .15) * pageHeight));
        float top = (float) (jsonDouble(field, "yRatio", 0) * pageHeight);
        float y = pageHeight - top - height;
        float fontSize = Math.max(8, Math.min(72, (float) jsonDouble(field, "fontSize", 12)));

        Set<String> hidden = new HashSet<>();
        if (field.has("hiddenRows") && field.get("hiddenRows").isJsonArray()) {
            for (JsonElement item : field.getAsJsonArray("hiddenRows")) {
                hidden.add(item.getAsString());
            }
        }

        try (PDPageContentStream content = new PDPageContentStream(
                document, page, PDPageContentStream.AppendMode.APPEND, true, true)) {
            applyDisplayRotation(content, rotation, rawWidth, rawHeight);
            PDExtendedGraphicsState previewBackground = new PDExtendedGraphicsState();
            previewBackground.setNonStrokingAlphaConstant(.94f);
            content.setGraphicsStateParameters(previewBackground);
            content.setNonStrokingColor(Color.WHITE);
            content.addRect(x, y, width, height);
            content.fill();
            previewBackground.setNonStrokingAlphaConstant(1f);
            content.setGraphicsStateParameters(previewBackground);
            content.setNonStrokingColor(Color.BLACK);

            float innerX = x + 7;
            float innerWidth = Math.max(10, width - 14);
            float cursorTop = y + height - 5;

            String approval = visibleValue(field, hidden, "approval");
            if (!approval.isEmpty()) {
                drawCenteredText(content, boldFont, fontSize, approval,
                        innerX, innerWidth, cursorTop - fontSize);
                cursorTop -= 17;
            }

            boolean hasSignatureRow = !visibleValue(field, hidden, "signText").isEmpty()
                    || !visibleValue(field, hidden, "signatureImage").isEmpty()
                    || !visibleValue(field, hidden, "committee").isEmpty();
            if (!hidden.contains("signatureLine") && hasSignatureRow) {
                float rowBottom = cursorTop - 42;
                drawDottedLine(content, innerX, innerX + innerWidth, rowBottom);

                String signText = visibleValue(field, hidden, "signText");
                String committee = visibleValue(field, hidden, "committee");
                float prefixWidth = signText.isEmpty() ? 0 : Math.min(42, innerWidth * .22f);
                float committeeWidth = committee.isEmpty() ? 0 : Math.min(86, innerWidth * .38f);
                float imageX = innerX + prefixWidth;
                float imageWidth = Math.max(0, innerWidth - prefixWidth - committeeWidth);

                if (!signText.isEmpty()) {
                    drawCenteredText(content, font, fontSize, signText,
                            innerX, prefixWidth, rowBottom + 3);
                }
                if (!committee.isEmpty()) {
                    drawCenteredText(content, font, fontSize, committee,
                            innerX + innerWidth - committeeWidth, committeeWidth, rowBottom + 3);
                }

                String imageData = visibleValue(field, hidden, "signatureImage");
                if (imageWidth > 0 && imageData.startsWith("data:image/")) {
                    drawSignatureImage(document, content, imageData, imageX, rowBottom + 2,
                            imageWidth, 38);
                }
                cursorTop = rowBottom;
            }

            String[] rowKeys = {"signerName", "position", "actingForPresident", "organization", "signedDate"};
            for (String key : rowKeys) {
                String value = visibleValue(field, hidden, key);
                if (value.isEmpty()) {
                    continue;
                }
                float rowBottom = cursorTop - 17;
                if (rowBottom < y) {
                    break;
                }
                drawDottedLine(content, innerX, innerX + innerWidth, rowBottom);
                drawCenteredText(content, font, fontSize, value,
                        innerX, innerWidth, rowBottom + 2);
                cursorTop = rowBottom;
            }
        }
    }

    private String visibleValue(JsonObject field, Set<String> hidden, String key) {
        return hidden.contains(key) ? "" : jsonString(field, key);
    }

    private void drawDottedLine(PDPageContentStream content, float startX,
            float endX, float y) throws IOException {
        // ไม่วาดเส้นใต้ข้อความ (จุดไข่ปลา) แล้ว เพื่อให้ตรงกับ UI ใน layout.html
    }

    private void drawSignatureImage(PDDocument document, PDPageContentStream content,
            String imageData, float x, float y,
            float areaWidth, float areaHeight) throws IOException {
        try {
            byte[] bytes = Base64.getDecoder().decode(imageData.substring(imageData.indexOf(',') + 1));
            BufferedImage bufferedImage = ImageIO.read(new ByteArrayInputStream(bytes));
            if (bufferedImage == null) {
                return;
            }
            PDImageXObject image = LosslessFactory.createFromImage(document, bufferedImage);
            float scale = Math.min(areaWidth / image.getWidth(), areaHeight / image.getHeight());
            float drawWidth = image.getWidth() * scale;
            float drawHeight = image.getHeight() * scale;
            content.drawImage(image, x + (areaWidth - drawWidth) / 2, y,
                    drawWidth, drawHeight);
        } catch (IllegalArgumentException ignored) {
        }
    }

    private void applyDisplayRotation(PDPageContentStream content, int rotation,
            float rawWidth, float rawHeight) throws IOException {
        switch (rotation) {
            case 90:
                content.transform(new Matrix(0, -1, 1, 0, 0, rawHeight));
                break;
            case 180:
                content.transform(new Matrix(-1, 0, 0, -1, rawWidth, rawHeight));
                break;
            case 270:
                content.transform(new Matrix(0, 1, -1, 0, rawWidth, 0));
                break;
            default:
                break;
        }
    }

    private void drawCenteredText(PDPageContentStream content, PDFont font, float size,
            String text, float x, float width, float y) throws IOException {
        String safeText = sanitizeForFont(font, text);
        if (safeText.isEmpty()) {
            return;
        }
        float textWidth = font.getStringWidth(safeText) / 1000f * size;
        content.beginText();
        content.setFont(font, size);
        content.newLineAtOffset(x + Math.max(2, (width - textWidth) / 2), y);
        content.showText(safeText);
        content.endText();
    }

    // ตัดอักขระควบคุม (control char เช่น U+0087 ที่มักติดมาจากการ copy-paste จาก Word/Excel)
    // และแทนที่อักขระใด ๆ ที่ฟอนต์ไม่มี glyph ด้วยช่องว่าง กัน PDF สร้างไม่สำเร็จทั้งฉบับ
    // เพราะมีตัวอักษรแปลกปลอมเพียงตัวเดียวในข้อมูล
    private String sanitizeForFont(PDFont font, String text) {
        if (text == null || text.isEmpty()) {
            return "";
        }
        StringBuilder sb = new StringBuilder(text.length());
        for (int i = 0; i < text.length(); i++) {
            char c = text.charAt(i);
            if (Character.isISOControl(c)) {
                continue;
            }
            try {
                font.encode(String.valueOf(c));
                sb.append(c);
            } catch (Exception unsupported) {
                sb.append(' ');
            }
        }
        return sb.toString().trim();
    }

    private JsonArray jsonArray(JsonObject object, String... keys) {
        for (String key : keys) {
            if (object.has(key) && object.get(key).isJsonArray()) {
                return object.getAsJsonArray(key);
            }
        }
        return null;
    }

    private String jsonString(JsonObject object, String... keys) {
        for (String key : keys) {
            if (object.has(key) && !object.get(key).isJsonNull()) {
                try {
                    return object.get(key).getAsString();
                } catch (Exception ignored) {
                }
            }
        }
        return "";
    }

    private int jsonInt(JsonObject object, String key, int defaultValue) {
        try {
            return object.get(key).getAsInt();
        } catch (Exception e) {
            return defaultValue;
        }
    }

    private double jsonDouble(JsonObject object, String key, double defaultValue) {
        try {
            return object.get(key).getAsDouble();
        } catch (Exception e) {
            return defaultValue;
        }
    }

    private String sanitizeDownloadName(String value) {
        if (value == null) {
            return "";
        }
        return value.trim().replaceAll("[^A-Za-z0-9._-]", "_");
    }

    // ---------- Serve current template ----------
    private void handleServeTemplate(HttpServletResponse resp) throws IOException {
        File f = new File(UPLOAD_DIR + TEMPLATE_NAME);
        if (!f.exists()) {
            resp.sendError(404, "ยังไม่มี template");
            return;
        }
        resp.setContentType("application/pdf");
        resp.setHeader("Cache-Control", "no-store");
        try (OutputStream os = resp.getOutputStream(); InputStream is = new FileInputStream(f)) {
            byte[] buf = new byte[8192];
            int r;
            while ((r = is.read(buf)) != -1) {
                os.write(buf, 0, r);
            }
        }
    }

    // ---------- Delete pages from template ----------
    private void deletePages(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        String pagesParam = req.getParameter("pages"); // รับค่า เช่น "1,2,5"
        if (pagesParam == null || pagesParam.trim().isEmpty()) {
            writeJsonError(resp, 400, "ไม่ได้ระบุหน้าที่จะลบ");
            return;
        }

        // แปลงอินพุตจาก 1-based (หน้า 1) เป็น 0-based (ดัชนี 0)
        Set<Integer> toDelete = new HashSet<>();
        try {
            for (String s : pagesParam.split(",")) {
                int page = Integer.parseInt(s.trim());
                if (page > 0) {
                    toDelete.add(page - 1);
                }
            }
        } catch (NumberFormatException e) {
            writeJsonError(resp, 400, "รูปแบบหมายเลขหน้าไม่ถูกต้อง");
            return;
        }

        File f = new File(UPLOAD_DIR + TEMPLATE_NAME);
        if (!f.exists()) {
            writeJsonError(resp, 404, "ยังไม่มี template ในระบบ");
            return;
        }

        // โหลดและดำเนินการลบหน้าในหน่วยความจำ
        byte[] updatedPdfBytes;
        int remainingPages;
        try (PDDocument doc = PDDocument.load(f)) {
            int totalPages = doc.getNumberOfPages();

            // ตรวจสอบความถูกต้องของช่วงหน้า
            for (int idx : toDelete) {
                if (idx < 0 || idx >= totalPages) {
                    writeJsonError(resp, 400, "หน้าที่ " + (idx + 1) + " ไม่มีอยู่จริงในเอกสาร (มีทั้งหมด " + totalPages + " หน้า)");
                    return;
                }
            }

            if (totalPages - toDelete.size() < 1) {
                writeJsonError(resp, 400, "ไม่สามารถลบหน้าทั้งหมดออกได้ เอกสารต้องมีอย่างน้อย 1 หน้า");
                return;
            }

            // เรียงลำดับจากหน้าท้ายสุดมาข้างหน้า เพื่อป้องกันปัญหาตำแหน่งดัชนีขยับขณะลบ
            List<Integer> sortedDesc = toDelete.stream()
                    .sorted(Comparator.reverseOrder())
                    .collect(Collectors.toList());

            for (int idx : sortedDesc) {
                doc.removePage(idx);
                // ขยับบุ๊กมาร์กสำหรับหน้าหลังจากจุดที่โดนลบ
                updateBookmarksAfterDelete(doc, idx);
            }

            remainingPages = doc.getNumberOfPages();

            // บันทึกลง Byte Array ก่อนเขียนทับดิสก์
            try (ByteArrayOutputStream baos = new ByteArrayOutputStream()) {
                doc.save(baos);
                updatedPdfBytes = baos.toByteArray();
            }

        } catch (Exception e) {
            writeJsonError(resp, 500, "เกิดข้อผิดพลาดในการประมวลผล PDF: " + e.getMessage());
            return;
        }

        // เขียนไฟล์ที่แก้ไขแล้วทับลงตำแหน่งเดิมอย่างปลอดภัย
        try (FileOutputStream fos = new FileOutputStream(f)) {
            fos.write(updatedPdfBytes);
        } catch (IOException e) {
            writeJsonError(resp, 500, "เกิดข้อผิดพลาดในการบันทึกไฟล์ทับ: " + e.getMessage());
            return;
        }

        // ตอบกลับ JSON พร้อมข้อมูลครบถ้วน
        Map<String, Object> result = new LinkedHashMap<>();
        result.put("success", true);
        result.put("message", "ลบหน้า " + pagesParam + " สำเร็จ");
        result.put("deletedPages", pagesParam);
        result.put("deletedCount", toDelete.size());
        result.put("remainingPages", remainingPages);
        writeJson(resp, 200, result);
    }

    // ---------- ฟังก์ชันจัดการ Bookmark หลังลบหน้า ----------
    private void updateBookmarksAfterDelete(PDDocument doc, int deletedIndex) throws IOException {
        PDDocumentOutline outline = doc.getDocumentCatalog().getDocumentOutline();
        if (outline == null) {
            return;
        }

        traverseAndAdjust(outline, item -> {
            PDPageDestination dest = resolveDestination(item);
            if (dest == null) {
                return;
            }

            // หากบุ๊กมาร์กอ้างอิงเป็น Object หน้าตรง ๆ (ไม่ระบุเป็นเลขหน้า) ข้ามไปได้เพราะระบบจัดการอ้างอิงให้แล้ว
            if (dest.getPage() != null) {
                return;
            }

            int pn = dest.getPageNumber();
            if (pn < 0) {
                return;
            }

            if (pn == deletedIndex) {
                // หากหน้าปลายทางถูกลบ ให้ชี้ไปที่หน้าก่อนหน้า หรือหน้าแรกสุดแทน
                dest.setPageNumber(Math.max(0, deletedIndex - 1));
                item.setDestination(dest);
            } else if (pn > deletedIndex) {
                // ขยับหมายเลขหน้าลดลง 1 ตำแหน่งสำหรับหน้าที่มีดัชนีอยู่หลังจุดที่ลบ
                dest.setPageNumber(pn - 1);
                item.setDestination(dest);
            }
        });
    }

    // ---------- Upload template ----------
    private void handleUploadTemplate(HttpServletRequest req, HttpServletResponse resp)
            throws IOException, ServletException {
        Part filePart = req.getPart("templatePdf");
        if (filePart == null || filePart.getSize() == 0) {
            resp.sendError(400, "ไม่พบไฟล์ templatePdf");
            return;
        }
        File dest = new File(UPLOAD_DIR + TEMPLATE_NAME);
        try (InputStream in = filePart.getInputStream(); OutputStream out = new FileOutputStream(dest)) {
            byte[] buf = new byte[8192];
            int r;
            while ((r = in.read(buf)) != -1) {
                out.write(buf, 0, r);
            }
        }
        resp.setContentType("text/plain; charset=UTF-8");
        resp.getWriter().write("OK");
    }

    // ---------- List bookmarks ----------
    private void handleListBookmarks(HttpServletResponse resp) throws IOException {
        File f = new File(UPLOAD_DIR + TEMPLATE_NAME);
        List<Map<String, Object>> results = new ArrayList<>();
        if (f.exists()) {
            try (PDDocument doc = PDDocument.load(f)) {
                PDDocumentOutline outline = doc.getDocumentCatalog().getDocumentOutline();
                if (outline != null) {
                    iterateBookmarks(doc, outline, results, 0);
                }
            } catch (Exception ignored) {
            }
        }
        resp.setContentType("application/json; charset=UTF-8");
        resp.getWriter().write(toJson(results));
    }

    private void iterateBookmarks(PDDocument doc, PDOutlineNode node,
            List<Map<String, Object>> results, int level) throws IOException {
        PDOutlineItem item = node.getFirstChild();
        while (item != null) {
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("title", item.getTitle());
            m.put("level", level);
            int pageIndex = resolvePageIndex(doc, item.getDestination());
            if (pageIndex < 0 && item.getAction() != null) {
                PDPageDestination d = resolveActionDestination(item.getAction());
                pageIndex = resolvePageIndex(doc, d);
            }
            m.put("pageIndex", pageIndex);
            results.add(m);

            if (item.hasChildren()) {
                iterateBookmarks(doc, item, results, level + 1);
            }
            item = item.getNextSibling();
        }
    }

    private int resolvePageIndex(PDDocument doc, PDDestination dest) throws IOException {
        if (dest instanceof PDPageDestination) {
            PDPageDestination d = (PDPageDestination) dest;
            PDPage page = d.getPage();
            if (page != null) {
                return doc.getPages().indexOf(page);
            }
            int pn = d.getPageNumber();
            if (pn >= 0) {
                return pn;
            }
        }
        return -1;
    }

    private PDPageDestination resolveActionDestination(org.apache.pdfbox.pdmodel.interactive.action.PDAction action) throws IOException {
        if (action instanceof org.apache.pdfbox.pdmodel.interactive.action.PDActionGoTo) {
            PDDestination d = ((org.apache.pdfbox.pdmodel.interactive.action.PDActionGoTo) action).getDestination();
            if (d instanceof PDPageDestination) {
                return (PDPageDestination) d;
            }
        }
        return null;
    }

    // ---------- List server files ----------
    private void handleServerFiles(HttpServletResponse resp) throws IOException {
        File dir = new File(UPLOAD_DIR);
        String[] list = dir.list((d, name)
                -> name.toLowerCase().endsWith(".pdf") && !name.equals(TEMPLATE_NAME));
        List<String> files = list == null ? Collections.emptyList()
                : Arrays.stream(list).sorted().collect(Collectors.toList());
        resp.setContentType("application/json; charset=UTF-8");
        resp.getWriter().write(toJson(files));
    }

    // ---------- Edit (insert / replace one / replace range) ----------
    private void handleEdit(HttpServletRequest req, HttpServletResponse resp)
            throws IOException, ServletException {
        String actionType = req.getParameter("actionType");
        int pageNumber;
        try {
            pageNumber = Integer.parseInt(req.getParameter("pageNumber"));
        } catch (Exception e) {
            writeJsonError(resp, 400, "pageNumber ไม่ถูกต้อง");
            return;
        }

        int replaceCount = 1;
        if ("replaceRange".equals(actionType)) {
            try {
                replaceCount = Math.max(1, Integer.parseInt(req.getParameter("replaceCount")));
            } catch (Exception ignore) {
                replaceCount = 1;
            }
        }

        if (!("insert".equals(actionType) || "replace".equals(actionType) || "replaceRange".equals(actionType))) {
            writeJsonError(resp, 400, "actionType ต้องเป็น insert/replace/replaceRange");
            return;
        }

        File templateFile = new File(UPLOAD_DIR + TEMPLATE_NAME);
        if (!templateFile.exists()) {
            writeJsonError(resp, 400, "ยังไม่มี template");
            return;
        }

        Part newPdfPart = null;
        try {
            newPdfPart = req.getPart("newPdf");
        } catch (IllegalStateException ignore) {
        }
        String serverFile = req.getParameter("serverFile");
        File newPdfFile = resolveNewPdfFile(newPdfPart, serverFile);
        if (newPdfFile == null || !newPdfFile.exists()) {
            writeJsonError(resp, 400, "ไม่พบไฟล์สำหรับแทรก/แทนที่");
            return;
        }

        try (PDDocument templateDoc = PDDocument.load(templateFile); PDDocument sourceDoc = PDDocument.load(newPdfFile)) {

            PDPageTree pages = templateDoc.getPages();
            int pageCount = pages.getCount();

            if ("insert".equals(actionType)) {
                if (pageNumber < 1 || pageNumber > pageCount + 1) {
                    writeJsonError(resp, 400, "หมายเลขหน้าต้องอยู่ในช่วง 1.." + (pageCount + 1));
                    return;
                }
                int targetIndex = pageNumber - 1;
                int insertedCount = sourceDoc.getNumberOfPages();
                PDPage firstInserted = insertPages(templateDoc, sourceDoc, targetIndex);
                updateBookmarksAfterInsert(templateDoc, targetIndex, insertedCount);

            } else if ("replace".equals(actionType)) {
                if (pageNumber < 1 || pageNumber > pageCount) {
                    writeJsonError(resp, 400, "หมายเลขหน้าต้องอยู่ในช่วง 1.." + pageCount);
                    return;
                }
                int targetIndex = pageNumber - 1;
                int insertedCount = sourceDoc.getNumberOfPages();

                PDPage[] removedHolder = new PDPage[1];
                PDPage firstInserted = replaceOnePage(templateDoc, sourceDoc, targetIndex, removedHolder);
                PDPage removedPage = removedHolder[0];

                updateBookmarksAfterReplaceOne(templateDoc, targetIndex, removedPage, firstInserted, insertedCount);

            } else { // replaceRange
                if (pageNumber < 1 || pageNumber > pageCount) {
                    writeJsonError(resp, 400, "หมายเลขหน้าเริ่มต้นต้องอยู่ในช่วง 1.." + pageCount);
                    return;
                }
                if (replaceCount < 1 || pageNumber - 1 + replaceCount > pageCount) {
                    writeJsonError(resp, 400, "ช่วงหน้าที่จะแทนที่เกินขอบเขตเอกสาร");
                    return;
                }
                int startIndex = pageNumber - 1;
                int insertedCount = sourceDoc.getNumberOfPages();

                ReplaceRangeResult res = replaceRange(templateDoc, sourceDoc, startIndex, replaceCount);
                updateBookmarksAfterReplaceRange(templateDoc, startIndex, replaceCount, res.firstInserted, insertedCount);
            }

            templateDoc.save(templateFile);
        } catch (Exception e) {
            e.printStackTrace();
            writeJsonError(resp, 500, "ผิดพลาด: " + e.getMessage());
            return;
        }

        writeJson(resp, 200, Collections.singletonMap("message", "อัปเดตสำเร็จ"));
    }

    // ---------- Insert / Replace helpers ----------
    private PDPage insertPages(PDDocument target, PDDocument source, int targetIndex) throws IOException {
        PDPageTree pages = target.getPages();
        int pageCount = pages.getCount();
        PDPage firstInserted = null;

        if (targetIndex >= pageCount) {
            for (int i = 0; i < source.getNumberOfPages(); i++) {
                PDPage imported = target.importPage(source.getPage(i));
                if (firstInserted == null) {
                    firstInserted = imported;
                }
            }
            return firstInserted;
        }

        PDPage ref = pages.get(targetIndex);
        for (int i = 0; i < source.getNumberOfPages(); i++) {
            PDPage imported = target.importPage(source.getPage(i));
            pages.insertBefore(imported, ref);
            if (firstInserted == null) {
                firstInserted = imported;
            }
        }
        return firstInserted;
    }

    private PDPage replaceOnePage(PDDocument target, PDDocument source, int targetIndex, PDPage[] removedOut) throws IOException {
        PDPageTree pages = target.getPages();
        PDPage ref = pages.get(targetIndex);
        removedOut[0] = ref;
        PDPage firstInserted = null;
        for (int i = 0; i < source.getNumberOfPages(); i++) {
            PDPage imported = target.importPage(source.getPage(i));
            pages.insertBefore(imported, ref);
            if (firstInserted == null) {
                firstInserted = imported;
            }
        }
        pages.remove(ref);
        return firstInserted;
    }

    private static class ReplaceRangeResult {

        PDPage firstInserted;
        List<PDPage> removedPages;
    }

    private ReplaceRangeResult replaceRange(PDDocument target, PDDocument source, int startIndex, int replaceCount) throws IOException {
        ReplaceRangeResult res = new ReplaceRangeResult();
        res.removedPages = new ArrayList<>();

        PDPageTree pages = target.getPages();
        for (int i = 0; i < replaceCount; i++) {
            res.removedPages.add(pages.get(startIndex + i));
        }

        PDPage ref = pages.get(startIndex);
        for (int i = 0; i < source.getNumberOfPages(); i++) {
            PDPage imported = target.importPage(source.getPage(i));
            pages.insertBefore(imported, ref);
            if (res.firstInserted == null) {
                res.firstInserted = imported;
            }
        }

        for (PDPage p : res.removedPages) {
            pages.remove(p);
        }
        return res;
    }

    // ---------- Bookmark updates (Insert/Replace) ----------
    private void updateBookmarksAfterInsert(PDDocument doc, int targetIndex, int insertedCount) throws IOException {
        PDDocumentOutline outline = doc.getDocumentCatalog().getDocumentOutline();
        if (outline == null || insertedCount == 0) {
            return;
        }
        traverseAndAdjust(outline, item -> {
            PDPageDestination dest = resolveDestination(item);
            if (dest == null) {
                return;
            }
            if (dest.getPage() != null) {
                return;
            }
            int pn = dest.getPageNumber();
            if (pn >= 0 && pn >= targetIndex) {
                dest.setPageNumber(pn + insertedCount);
                item.setDestination(dest);
            }
        });
    }

    private void updateBookmarksAfterReplaceOne(PDDocument doc, int targetIndex, PDPage removedPage, PDPage firstInserted, int insertedCount) throws IOException {
        PDDocumentOutline outline = doc.getDocumentCatalog().getDocumentOutline();
        if (outline == null) {
            return;
        }
        final int delta = insertedCount - 1;

        traverseAndAdjust(outline, item -> {
            PDPageDestination dest = resolveDestination(item);
            if (dest == null) {
                return;
            }

            PDPage pageRef = dest.getPage();
            if (pageRef != null) {
                if (pageRef == removedPage && firstInserted != null) {
                    dest.setPage(firstInserted);
                    item.setDestination(dest);
                }
                return;
            }

            int pn = dest.getPageNumber();
            if (pn < 0) {
                return;
            }

            if (pn == targetIndex) {
                if (firstInserted != null) {
                    dest.setPage(firstInserted);
                } else {
                    dest.setPageNumber(targetIndex);
                }
                item.setDestination(dest);
            } else if (pn > targetIndex && delta != 0) {
                dest.setPageNumber(pn + delta);
                item.setDestination(dest);
            }
        });
    }

    private void updateBookmarksAfterReplaceRange(PDDocument doc, int startIndex, int replaceCount, PDPage firstInserted, int insertedCount) throws IOException {
        PDDocumentOutline outline = doc.getDocumentCatalog().getDocumentOutline();
        if (outline == null) {
            return;
        }
        final int endIndex = startIndex + replaceCount - 1;
        final int delta = insertedCount - replaceCount;

        traverseAndAdjust(outline, item -> {
            PDPageDestination dest = resolveDestination(item);
            if (dest == null) {
                return;
            }
            if (dest.getPage() != null) {
                return;
            }

            int pn = dest.getPageNumber();
            if (pn < 0) {
                return;
            }

            if (pn >= startIndex && pn <= endIndex) {
                if (firstInserted != null) {
                    dest.setPage(firstInserted);
                } else {
                    dest.setPageNumber(startIndex);
                }
                item.setDestination(dest);
            } else if (pn > endIndex && delta != 0) {
                dest.setPageNumber(pn + delta);
                item.setDestination(dest);
            }
        });
    }

    // ---------- Bookmark traversal utils ----------
    private interface ItemVisitor {

        void visit(PDOutlineItem item) throws IOException;
    }

    private void traverseAndAdjust(PDOutlineNode node, ItemVisitor visitor) throws IOException {
        PDOutlineItem it = node.getFirstChild();
        while (it != null) {
            visitor.visit(it);
            if (it.hasChildren()) {
                traverseAndAdjust(it, visitor);
            }
            it = it.getNextSibling();
        }
    }

    private PDPageDestination resolveDestination(PDOutlineItem item) throws IOException {
        PDDestination d = item.getDestination();
        if (d instanceof PDPageDestination) {
            return (PDPageDestination) d;
        }
        if (item.getAction() instanceof org.apache.pdfbox.pdmodel.interactive.action.PDActionGoTo) {
            PDDestination d2 = ((org.apache.pdfbox.pdmodel.interactive.action.PDActionGoTo) item.getAction()).getDestination();
            if (d2 instanceof PDPageDestination) {
                return (PDPageDestination) d2;
            }
        }
        return null;
    }

    // ---------- Helpers ----------
    private File resolveNewPdfFile(Part uploadPart, String serverFileName) throws IOException {
        if (uploadPart != null && uploadPart.getSize() > 0) {
            File tmp = new File(UPLOAD_DIR, "incoming_" + System.currentTimeMillis() + ".pdf");
            try (InputStream in = uploadPart.getInputStream(); OutputStream out = new FileOutputStream(tmp)) {
                byte[] buf = new byte[8192];
                int r;
                while ((r = in.read(buf)) != -1) {
                    out.write(buf, 0, r);
                }
            }
            return tmp;
        }
        if (serverFileName != null && !serverFileName.isEmpty()) {
            String safe = serverFileName.replaceAll("[\\\\/]+", "");
            File f = new File(UPLOAD_DIR, safe);
            if (f.exists() && f.getName().toLowerCase().endsWith(".pdf")) {
                return f;
            }
        }
        return null;
    }

    private void writeJson(HttpServletResponse resp, int code, Map<String, ?> obj) throws IOException {
        resp.setStatus(code);
        resp.setContentType("application/json; charset=UTF-8");
        resp.getWriter().write(toJson(obj));
    }

    private void writeJsonError(HttpServletResponse resp, int code, String msg) throws IOException {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("error", true);
        m.put("message", msg);
        writeJson(resp, code, m);
    }

    private String toJson(Object o) {
        if (o == null) {
            return "null";
        }
        if (o instanceof String) {
            return "\"" + escape((String) o) + "\"";
        }
        if (o instanceof Number || o instanceof Boolean) {
            return String.valueOf(o);
        }
        if (o instanceof Map) {
            @SuppressWarnings("unchecked")
            Map<String, Object> m = (Map<String, Object>) o;
            return "{" + m.entrySet().stream()
                    .map(e -> "\"" + escape(e.getKey()) + "\":" + toJson(e.getValue()))
                    .collect(Collectors.joining(",")) + "}";
        }
        if (o instanceof Collection) {
            Collection<?> c = (Collection<?>) o;
            return "[" + c.stream().map(this::toJson).collect(Collectors.joining(",")) + "]";
        }
        return "\"" + escape(String.valueOf(o)) + "\"";
    }

    private String escape(String s) {
        return s.replace("\\", "\\\\").replace("\"", "\\\"").replace("\n", "\\n");
    }
}
