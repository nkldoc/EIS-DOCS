package th.ac.nmu.eisdocs.notification;

import org.springframework.context.annotation.Configuration;
import org.springframework.web.socket.config.annotation.EnableWebSocket;
import org.springframework.web.socket.config.annotation.WebSocketConfigurer;
import org.springframework.web.socket.config.annotation.WebSocketHandlerRegistry;

@Configuration
@EnableWebSocket
public class NotificationWebSocketConfig implements WebSocketConfigurer {
    private final NotificationSocketHandler handler;
    public NotificationWebSocketConfig(NotificationSocketHandler handler) { this.handler = handler; }
    @Override public void registerWebSocketHandlers(WebSocketHandlerRegistry registry) {
        registry.addHandler(handler, "/ws/notifications")
            .setAllowedOrigins(
                "http://localhost", "http://127.0.0.1",
                "http://localhost:8080", "http://127.0.0.1:8080",
                "https://eis-docs.nmu.ac.th", "http://eis-docs.nmu.ac.th",
                "https://dev-eis.nmu.ac.th", "https://doc-eis.nmu.ac.th",
                "https://eis.nmu.ac.th"
            );
    }
}
