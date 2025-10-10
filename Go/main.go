package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
	
	"github.com/gin-contrib/sessions"
	"github.com/gin-contrib/sessions/cookie"
	"github.com/gin-gonic/gin"
)

type IPResponse struct {
	Code        int                    `json:"code"`
	Msg         string                 `json:"msg"`
	IPAddress   string                 `json:"ipAddress"`
	IPLocation  string                 `json:"ipLocation"`
	IPLong      string                 `json:"ipLong"`
	ResolvedIPs []IPResponse           `json:"resolvedIPs"`
	IPLocation2 map[string]interface{} `json:"ipLocation2"`
	Error       string                 `json:"error"`
	Input       string                 `json:"input"`
}

func main() {
	r := gin.Default()
	store := cookie.NewStore([]byte("secret"))
	r.Use(sessions.Sessions("query_session", store))
	
	r.LoadHTMLGlob("templates/*")
	r.Static("/static", "./static")
	
	r.GET("/", indexHandler)
	r.POST("/", postHandler)
	
	r.Run(":8080")
}

func indexHandler(c *gin.Context) {
	session := sessions.Default(c)
	clientIP := getClientIP(c)
	clientData := callIPAPI(clientIP)
	
	queryInput := c.Query("ip")
	var queryResult *IPResponse
	isDomainQuery := false
	
	if queryInput != "" {
		queryResult = callIPAPI(queryInput)
		if queryResult != nil && queryResult.ResolvedIPs != nil {
			isDomainQuery = true
		}
		
		// 保存查询历史
		var history []string
		historyJSON := session.Get("query_history")
		if historyJSON != nil {
			json.Unmarshal(historyJSON.([]byte), &history)
		}
		
		// 去重并添加到历史
		found := false
		for _, item := range history {
			if item == queryInput {
				found = true
				break
			}
		}
		if !found {
			history = append([]string{queryInput}, history...)
			if len(history) > 10 {
				history = history[:10]
			}
			historyBytes, _ := json.Marshal(history)
			session.Set("query_history", historyBytes)
			session.Save()
		}
	}
	
	var history []string
	historyJSON := session.Get("query_history")
	if historyJSON != nil {
		json.Unmarshal(historyJSON.([]byte), &history)
	}
	
	c.HTML(http.StatusOK, "index.html", gin.H{
		"clientData":     clientData,
		"queryResult":    queryResult,
		"queryInput":     queryInput,
		"isDomainQuery":  isDomainQuery,
		"queryHistory":   history,
		"serverTime":     time.Now().Format("2006年01月02日 15:04:05"),
	})
}

func postHandler(c *gin.Context) {
	queryInput := c.PostForm("ip")
	if queryInput != "" {
		c.Redirect(http.StatusFound, "/?ip="+queryInput)
		return
	}
	c.Redirect(http.StatusFound, "/")
}

func getClientIP(c *gin.Context) string {
	headers := []string{"X-Forwarded-For", "X-Real-IP", "X-Client-IP"}
	for _, header := range headers {
		ip := c.GetHeader(header)
		if ip != "" {
			ips := strings.Split(ip, ",")
			for _, ip := range ips {
				ip = strings.TrimSpace(ip)
				if isValidIP(ip) {
					return ip
				}
			}
		}
	}
	return c.ClientIP()
}

func isValidIP(ip string) bool {
	return strings.Count(ip, ".") == 3
}

func callIPAPI(input string) *IPResponse {
	if input == "" {
		return nil
	}
	
	client := &http.Client{Timeout: 10 * time.Second}
	url := "https://apikey.net/api/index?ip=" + input
	
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return &IPResponse{Error: "请求创建失败: " + err.Error(), Input: input}
	}
	req.Header.Set("User-Agent", "IP Query Tool/1.0")
	
	resp, err := client.Do(req)
	if err != nil {
		return &IPResponse{Error: "API请求失败: " + err.Error(), Input: input}
	}
	defer resp.Body.Close()
	
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return &IPResponse{Error: "读取响应失败: " + err.Error(), Input: input}
	}
	
	var result IPResponse
	err = json.Unmarshal(body, &result)
	if err != nil {
		return &IPResponse{Error: "解析响应失败: " + err.Error(), Input: input}
	}
	
	return &result
}