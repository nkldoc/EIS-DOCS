package th.ac.nmu.eisdocs.notification;

import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.util.Base64;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;

@Service
public class WebSocketTokenService {
    @Value("${eis-docs.websocket-secret:}") private String secret;
    @Value("${spring.datasource.password:}") private String databasePassword;

    public String userFrom(String query) {
        try {
            if (effectiveSecret().isBlank() || query == null) return null;
            String token = null;
            for (String item : query.split("&")) { String[] pair = item.split("=", 2); if (pair.length == 2 && "token".equals(pair[0])) token = URLDecoder.decode(pair[1], StandardCharsets.UTF_8); }
            if (token == null) return null;
            String[] parts = token.split("\\.", 2); if (parts.length != 2 || !constantTime(parts[1], sign(parts[0]))) return null;
            String json = new String(Base64.getUrlDecoder().decode(parts[0]), StandardCharsets.UTF_8);
            String user = json.replaceAll(".*\\\"u\\\"\\s*:\\s*\\\"([^\\\"]+)\\\".*", "$1");
            String expiry = json.replaceAll(".*\\\"e\\\"\\s*:\\s*(\\d+).*", "$1");
            if (user.equals(json) || expiry.equals(json) || Long.parseLong(expiry) < System.currentTimeMillis() / 1000) return null;
            return user;
        } catch (Exception ignored) { return null; }
    }

    private String sign(String payload) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256"); mac.init(new SecretKeySpec(effectiveSecret().getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
        return Base64.getUrlEncoder().withoutPadding().encodeToString(mac.doFinal(payload.getBytes(StandardCharsets.UTF_8)));
    }
    private String effectiveSecret() {
        if (secret != null && !secret.isBlank()) return secret;
        if (databasePassword == null || databasePassword.isBlank()) return "";
        try {
            java.security.MessageDigest digest = java.security.MessageDigest.getInstance("SHA-256");
            byte[] bytes = digest.digest((databasePassword + "|eis-docs-websocket-v1").getBytes(StandardCharsets.UTF_8));
            StringBuilder output = new StringBuilder(); for (byte value : bytes) output.append(String.format("%02x", value));
            return output.toString();
        } catch (Exception ignored) { return ""; }
    }
    private boolean constantTime(String left, String right) {
        if (left.length() != right.length()) return false; int value = 0; for (int i = 0; i < left.length(); i++) value |= left.charAt(i) ^ right.charAt(i); return value == 0;
    }
}
