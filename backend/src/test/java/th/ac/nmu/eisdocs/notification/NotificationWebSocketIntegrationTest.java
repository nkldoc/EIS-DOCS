package th.ac.nmu.eisdocs.notification;

import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.WebSocket;
import java.net.http.WebSocketHandshakeException;
import java.nio.charset.StandardCharsets;
import java.util.Base64;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.CompletionStage;
import java.util.concurrent.TimeUnit;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import org.junit.jupiter.api.Test;
import static org.junit.jupiter.api.Assertions.*;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.SpringBootConfiguration;
import org.springframework.boot.autoconfigure.EnableAutoConfiguration;
import org.springframework.boot.autoconfigure.jdbc.DataSourceAutoConfiguration;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.boot.test.web.server.LocalServerPort;
import org.springframework.context.annotation.Import;

@SpringBootTest(classes=NotificationWebSocketIntegrationTest.TestApp.class,
    webEnvironment=SpringBootTest.WebEnvironment.RANDOM_PORT,
    properties={"server.servlet.context-path=/eis-docs", "eis-docs.websocket-secret=integration-test-only"})
class NotificationWebSocketIntegrationTest {
    @SpringBootConfiguration
    @EnableAutoConfiguration(exclude=DataSourceAutoConfiguration.class)
    @Import({NotificationWebSocketConfig.class, NotificationSocketHandler.class, WebSocketTokenService.class})
    static class TestApp {}
    @LocalServerPort int port;
    @Autowired NotificationSocketHandler handler;

    String token() throws Exception {
        String json="{\"u\":\"ws-test-user\",\"e\":"+(System.currentTimeMillis()/1000+60)+"}";
        String payload=Base64.getUrlEncoder().withoutPadding().encodeToString(json.getBytes(StandardCharsets.UTF_8));
        Mac mac=Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec("integration-test-only".getBytes(StandardCharsets.UTF_8),"HmacSHA256"));
        return payload+"."+Base64.getUrlEncoder().withoutPadding().encodeToString(mac.doFinal(payload.getBytes(StandardCharsets.UTF_8)));
    }
    URI uri(String token) { return URI.create("ws://localhost:"+port+"/eis-docs/ws/notifications?token="+token); }
    static class Listener implements WebSocket.Listener {
        final CompletableFuture<String> message=new CompletableFuture<>();
        final CompletableFuture<Integer> closed=new CompletableFuture<>();
        public void onOpen(WebSocket socket) { socket.request(1); }
        public CompletionStage<?> onText(WebSocket socket,CharSequence data,boolean last) {
            message.complete(data.toString()); socket.request(1); return null;
        }
        public CompletionStage<?> onClose(WebSocket socket,int code,String reason) { closed.complete(code); return null; }
    }
    @Test void namedOriginsReceiveUserNotification() throws Exception {
        for (String origin:new String[]{"https://dev-eis.nmu.ac.th","https://doc-eis.nmu.ac.th","https://eis.nmu.ac.th","http://localhost"}) {
            Listener listener=new Listener();
            WebSocket socket=HttpClient.newHttpClient().newWebSocketBuilder().header("Origin",origin).buildAsync(uri(token()),listener).get(5,TimeUnit.SECONDS);
            try {
                // The HTTP handshake may finish just before session registration.
                for(int i=0;i<30 && !listener.message.isDone();i++) {
                    handler.publish("ws-test-user","{\"type\":\"EIS_DOCS_NOTIFICATION\"}");
                    Thread.sleep(50);
                }
                assertTrue(listener.message.get(3,TimeUnit.SECONDS).contains("EIS_DOCS_NOTIFICATION"));
            } finally { socket.sendClose(1000,"test complete").join(); }
        }
    }
    @Test void untrustedOriginIsRejected() throws Exception {
        try {
            HttpClient.newHttpClient().newWebSocketBuilder().header("Origin","https://untrusted.example").buildAsync(uri(token()),new Listener()).get(5,TimeUnit.SECONDS);
            fail("Untrusted origin accepted");
        } catch (java.util.concurrent.ExecutionException error) {
            assertInstanceOf(WebSocketHandshakeException.class,error.getCause());
            assertEquals(403,((WebSocketHandshakeException)error.getCause()).getResponse().statusCode());
        }
    }
    @Test void invalidTokenIsRejected() throws Exception {
        Listener listener=new Listener();
        HttpClient.newHttpClient().newWebSocketBuilder().header("Origin","https://dev-eis.nmu.ac.th").buildAsync(uri("invalid"),listener).get(5,TimeUnit.SECONDS);
        assertEquals(1003,listener.closed.get(5,TimeUnit.SECONDS));
    }
}
