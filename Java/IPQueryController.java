@Controller
@SessionAttributes("queryHistory")
public class IPQueryController {
    
    @Autowired
    private RestTemplate restTemplate;
    
    @GetMapping("/")
    public String index(HttpServletRequest request, 
                       HttpSession session,
                       @RequestParam(required = false) String ip,
                       Model model) {
        
        String clientIP = getClientIP(request);
        Map<String, Object> clientData = callIPAPI(clientIP);
        
        List<String> queryHistory = (List<String>) session.getAttribute("queryHistory");
        if (queryHistory == null) {
            queryHistory = new ArrayList<>();
            session.setAttribute("queryHistory", queryHistory);
        }
        
        if (ip != null && !ip.trim().isEmpty()) {
            Map<String, Object> queryResult = callIPAPI(ip);
            model.addAttribute("queryResult", queryResult);
            model.addAttribute("queryInput", ip);
            
            // 更新历史记录
            if (!queryHistory.contains(ip)) {
                queryHistory.add(0, ip);
                if (queryHistory.size() > 10) {
                    queryHistory.remove(queryHistory.size() - 1);
                }
            }
            
            // 检查是否为域名查询
            if (queryResult.containsKey("resolvedIPs")) {
                model.addAttribute("isDomainQuery", true);
            }
        }
        
        model.addAttribute("clientData", clientData);
        model.addAttribute("queryHistory", queryHistory);
        model.addAttribute("serverTime", LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy年MM月dd日 HH:mm:ss")));
        
        return "index";
    }
    
    @PostMapping("/")
    public String queryIP(@RequestParam String ip) {
        return "redirect:/?ip=" + URLEncoder.encode(ip, StandardCharsets.UTF_8);
    }
    
    private String getClientIP(HttpServletRequest request) {
        String[] headers = {"X-Forwarded-For", "X-Real-IP", "X-Client-IP"};
        
        for (String header : headers) {
            String ip = request.getHeader(header);
            if (ip != null && !ip.isEmpty() && !"unknown".equalsIgnoreCase(ip)) {
                return ip.split(",")[0].trim();
            }
        }
        return request.getRemoteAddr();
    }
    
    private Map<String, Object> callIPAPI(String input) {
        try {
            String url = "https://apikey.net/api/index?ip=" + URLEncoder.encode(input, "UTF-8");
            String response = restTemplate.getForObject(url, String.class);
            return new ObjectMapper().readValue(response, Map.class);
        } catch (Exception e) {
            Map<String, Object> error = new HashMap<>();
            error.put("error", "API请求失败: " + e.getMessage());
            error.put("input", input);
            return error;
        }
    }
}