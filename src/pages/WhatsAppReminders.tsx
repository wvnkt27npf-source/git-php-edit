import { useState, useMemo } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ScrollArea } from "@/components/ui/scroll-area";
import {
  MessageCircle,
  Search,
  ArrowUpDown,
  ArrowUp,
  ArrowDown,
  UserCircle,
  Users,
  FileText,
  Package,
  Truck,
  Phone,
  Send,
} from "lucide-react";

// Mock data - replace with actual API data
const mockInvoices = [
  {
    id: 1,
    invoice_no: "INV-001",
    date: "2024-01-15",
    party_name: "ABC Textiles",
    party_phone: "9876543210",
    agent_name: "Ramesh Kumar",
    agent_phone: "9988776655",
    total_amount: 50000,
    paid_amount: 20000,
    status: "Partial",
    has_invoice: true,
    has_packing: true,
    has_bilti: false,
  },
  {
    id: 2,
    invoice_no: "INV-002",
    date: "2024-01-18",
    party_name: "XYZ Fabrics",
    party_phone: "9123456789",
    agent_name: "Ramesh Kumar",
    agent_phone: "9988776655",
    total_amount: 75000,
    paid_amount: 0,
    status: "Open",
    has_invoice: true,
    has_packing: false,
    has_bilti: true,
  },
  {
    id: 3,
    invoice_no: "INV-003",
    date: "2024-01-20",
    party_name: "Direct Party 1",
    party_phone: "9555666777",
    agent_name: "",
    agent_phone: "",
    total_amount: 30000,
    paid_amount: 10000,
    status: "Partial",
    has_invoice: true,
    has_packing: true,
    has_bilti: true,
  },
  {
    id: 4,
    invoice_no: "INV-004",
    date: "2024-01-22",
    party_name: "PQR Mills",
    party_phone: "9444555666",
    agent_name: "Suresh Sharma",
    agent_phone: "9111222333",
    total_amount: 120000,
    paid_amount: 50000,
    status: "Partial",
    has_invoice: true,
    has_packing: false,
    has_bilti: false,
  },
  {
    id: 5,
    invoice_no: "INV-005",
    date: "2024-01-25",
    party_name: "Direct Party 2",
    party_phone: "9777888999",
    agent_name: "",
    agent_phone: "",
    total_amount: 45000,
    paid_amount: 0,
    status: "Open",
    has_invoice: false,
    has_packing: false,
    has_bilti: false,
  },
  {
    id: 6,
    invoice_no: "INV-006",
    date: "2024-01-28",
    party_name: "LMN Traders",
    party_phone: "9222333444",
    agent_name: "Suresh Sharma",
    agent_phone: "9111222333",
    total_amount: 85000,
    paid_amount: 85000,
    status: "Paid",
    has_invoice: true,
    has_packing: true,
    has_bilti: true,
  },
];

type SortField = "invoice_no" | "date" | "party_name" | "total_amount" | "status";
type SortDirection = "asc" | "desc";

interface Invoice {
  id: number;
  invoice_no: string;
  date: string;
  party_name: string;
  party_phone: string;
  agent_name: string;
  agent_phone: string;
  total_amount: number;
  paid_amount: number;
  status: string;
  has_invoice: boolean;
  has_packing: boolean;
  has_bilti: boolean;
}

const WhatsAppReminders = () => {
  const [searchTerm, setSearchTerm] = useState("");
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [sortField, setSortField] = useState<SortField>("date");
  const [sortDirection, setSortDirection] = useState<SortDirection>("desc");

  // Filter out paid invoices for reminders
  const outstandingInvoices = mockInvoices.filter(
    (inv) => inv.status !== "Paid" && inv.status !== "Closed"
  );

  // Group by Agent
  const groupedByAgent = useMemo(() => {
    const groups: Record<string, Invoice[]> = {};
    
    outstandingInvoices.forEach((inv) => {
      const agent = inv.agent_name || "DIRECT";
      if (!groups[agent]) {
        groups[agent] = [];
      }
      groups[agent].push(inv);
    });
    
    return groups;
  }, [outstandingInvoices]);

  // Apply filters and sorting
  const filterAndSort = (invoices: Invoice[]) => {
    let filtered = invoices.filter((inv) => {
      const matchesSearch =
        inv.invoice_no.toLowerCase().includes(searchTerm.toLowerCase()) ||
        inv.party_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        inv.party_phone.includes(searchTerm);
      
      const matchesStatus =
        statusFilter === "all" || inv.status === statusFilter;
      
      return matchesSearch && matchesStatus;
    });

    // Sort
    filtered.sort((a, b) => {
      let aVal: string | number = a[sortField];
      let bVal: string | number = b[sortField];

      if (sortField === "total_amount") {
        aVal = Number(aVal);
        bVal = Number(bVal);
      } else {
        aVal = String(aVal).toLowerCase();
        bVal = String(bVal).toLowerCase();
      }

      if (aVal < bVal) return sortDirection === "asc" ? -1 : 1;
      if (aVal > bVal) return sortDirection === "asc" ? 1 : -1;
      return 0;
    });

    return filtered;
  };

  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === "asc" ? "desc" : "asc");
    } else {
      setSortField(field);
      setSortDirection("asc");
    }
  };

  const getSortIcon = (field: SortField) => {
    if (sortField !== field) {
      return <ArrowUpDown className="ml-1 h-3 w-3 opacity-50" />;
    }
    return sortDirection === "asc" ? (
      <ArrowUp className="ml-1 h-3 w-3" />
    ) : (
      <ArrowDown className="ml-1 h-3 w-3" />
    );
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case "Open":
        return <Badge variant="destructive">{status}</Badge>;
      case "Partial":
        return <Badge className="bg-amber-500 hover:bg-amber-600">{status}</Badge>;
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  const handleSendWhatsApp = (invoice: Invoice, recipientType: "agent" | "party") => {
    const phone = recipientType === "agent" ? invoice.agent_phone : invoice.party_phone;
    const outstanding = invoice.total_amount - invoice.paid_amount;
    const message = `Outstanding Reminder:\nInvoice: #${invoice.invoice_no}\nParty: ${invoice.party_name}\nOutstanding: ₹${outstanding.toLocaleString()}`;
    
    // In real implementation, this would call the UltraMsg API
    console.log(`Sending WhatsApp to ${recipientType}:`, phone, message);
    alert(`WhatsApp would be sent to ${recipientType}: ${phone}`);
  };

  const InvoiceTable = ({ invoices, isDirectParty = false }: { invoices: Invoice[]; isDirectParty?: boolean }) => {
    const filteredInvoices = filterAndSort(invoices);

    if (filteredInvoices.length === 0) {
      return (
        <div className="text-center py-8 text-muted-foreground">
          No outstanding invoices found
        </div>
      );
    }

    return (
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 px-2 font-medium"
                onClick={() => handleSort("invoice_no")}
              >
                Invoice # {getSortIcon("invoice_no")}
              </Button>
            </TableHead>
            <TableHead>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 px-2 font-medium"
                onClick={() => handleSort("date")}
              >
                Date {getSortIcon("date")}
              </Button>
            </TableHead>
            <TableHead>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 px-2 font-medium"
                onClick={() => handleSort("party_name")}
              >
                Party {getSortIcon("party_name")}
              </Button>
            </TableHead>
            <TableHead>Documents</TableHead>
            <TableHead>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 px-2 font-medium"
                onClick={() => handleSort("total_amount")}
              >
                Outstanding {getSortIcon("total_amount")}
              </Button>
            </TableHead>
            <TableHead>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 px-2 font-medium"
                onClick={() => handleSort("status")}
              >
                Status {getSortIcon("status")}
              </Button>
            </TableHead>
            <TableHead>Action</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {filteredInvoices.map((inv) => {
            const outstanding = inv.total_amount - inv.paid_amount;
            const sendTo = isDirectParty ? "party" : "agent";
            const sendToLabel = isDirectParty ? "Party" : "Agent";
            const phoneAvailable = isDirectParty ? inv.party_phone : inv.agent_phone;

            return (
              <TableRow key={inv.id}>
                <TableCell className="font-medium">#{inv.invoice_no}</TableCell>
                <TableCell>
                  {new Date(inv.date).toLocaleDateString("en-IN", {
                    day: "2-digit",
                    month: "short",
                    year: "numeric",
                  })}
                </TableCell>
                <TableCell>
                  <div>
                    <div className="font-medium">{inv.party_name}</div>
                    <div className="text-sm text-muted-foreground flex items-center gap-1">
                      <Phone className="h-3 w-3" />
                      {inv.party_phone}
                    </div>
                  </div>
                </TableCell>
                <TableCell>
                  <div className="flex gap-1">
                    {inv.has_invoice && (
                      <Badge variant="outline" className="text-xs bg-green-50 text-green-700 border-green-200">
                        <FileText className="h-3 w-3 mr-1" />
                        INV
                      </Badge>
                    )}
                    {inv.has_packing && (
                      <Badge variant="outline" className="text-xs bg-blue-50 text-blue-700 border-blue-200">
                        <Package className="h-3 w-3 mr-1" />
                        PKG
                      </Badge>
                    )}
                    {inv.has_bilti && (
                      <Badge variant="outline" className="text-xs bg-gray-50 text-gray-700 border-gray-200">
                        <Truck className="h-3 w-3 mr-1" />
                        BLT
                      </Badge>
                    )}
                    {!inv.has_invoice && !inv.has_packing && !inv.has_bilti && (
                      <Badge variant="destructive" className="text-xs">
                        No Docs
                      </Badge>
                    )}
                  </div>
                </TableCell>
                <TableCell className="font-semibold text-destructive">
                  ₹{outstanding.toLocaleString()}
                </TableCell>
                <TableCell>{getStatusBadge(inv.status)}</TableCell>
                <TableCell>
                  {phoneAvailable ? (
                    <Button
                      size="sm"
                      className="bg-green-600 hover:bg-green-700 text-white"
                      onClick={() => handleSendWhatsApp(inv, sendTo as "agent" | "party")}
                    >
                      <MessageCircle className="h-4 w-4 mr-1" />
                      Send to {sendToLabel}
                    </Button>
                  ) : (
                    <span className="text-muted-foreground text-sm">No Phone</span>
                  )}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    );
  };

  // Get unique agents (excluding DIRECT)
  const agents = Object.keys(groupedByAgent).filter((a) => a !== "DIRECT");
  const hasDirectParties = !!groupedByAgent["DIRECT"];

  return (
    <div className="min-h-screen bg-background p-4 md:p-6">
      {/* Header */}
      <Card className="mb-6 bg-gradient-to-r from-green-600 to-green-700 text-white border-0">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-2xl">
            <MessageCircle className="h-7 w-7" />
            WhatsApp Reminders
          </CardTitle>
          <p className="text-green-100">
            Agent ho to Agent ko PDF jayega, DIRECT ho to Party ko jayega
          </p>
        </CardHeader>
      </Card>

      {/* Filters */}
      <Card className="mb-6">
        <CardContent className="pt-6">
          <div className="flex flex-col md:flex-row gap-4">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search by Invoice No, Party Name, Phone..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="pl-10"
              />
            </div>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-full md:w-48">
                <SelectValue placeholder="Filter by Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="Open">Open</SelectItem>
                <SelectItem value="Partial">Partial</SelectItem>
                <SelectItem value="Pending">Pending</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Tabs for Agent vs Direct */}
      <Tabs defaultValue="agents" className="space-y-4">
        <TabsList className="grid w-full grid-cols-2 max-w-md">
          <TabsTrigger value="agents" className="flex items-center gap-2">
            <Users className="h-4 w-4" />
            Agent Parties ({agents.length})
          </TabsTrigger>
          <TabsTrigger value="direct" className="flex items-center gap-2">
            <UserCircle className="h-4 w-4" />
            Direct Parties ({groupedByAgent["DIRECT"]?.length || 0})
          </TabsTrigger>
        </TabsList>

        {/* Agent Parties Tab */}
        <TabsContent value="agents" className="space-y-4">
          {agents.length === 0 ? (
            <Card>
              <CardContent className="py-8 text-center text-muted-foreground">
                No agent parties with outstanding invoices
              </CardContent>
            </Card>
          ) : (
            agents.map((agentName) => {
              const agentInvoices = groupedByAgent[agentName];
              const firstInvoice = agentInvoices[0];
              const totalOutstanding = agentInvoices.reduce(
                (sum, inv) => sum + (inv.total_amount - inv.paid_amount),
                0
              );

              return (
                <Card key={agentName} className="overflow-hidden">
                  <CardHeader className="bg-gradient-to-r from-indigo-600 to-purple-600 text-white">
                    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                      <div className="flex items-center gap-2">
                        <Users className="h-5 w-5" />
                        <CardTitle className="text-lg">Agent: {agentName}</CardTitle>
                      </div>
                      <div className="flex items-center gap-4 text-sm">
                        <span className="flex items-center gap-1">
                          <Phone className="h-4 w-4" />
                          {firstInvoice.agent_phone}
                        </span>
                        <Badge variant="secondary" className="bg-white/20 text-white hover:bg-white/30">
                          {agentInvoices.length} Invoices
                        </Badge>
                        <Badge variant="secondary" className="bg-white/20 text-white hover:bg-white/30">
                          ₹{totalOutstanding.toLocaleString()} Outstanding
                        </Badge>
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent className="p-0">
                    <ScrollArea className="max-h-96">
                      <InvoiceTable invoices={agentInvoices} isDirectParty={false} />
                    </ScrollArea>
                  </CardContent>
                </Card>
              );
            })
          )}
        </TabsContent>

        {/* Direct Parties Tab */}
        <TabsContent value="direct">
          <Card className="overflow-hidden">
            <CardHeader className="bg-gradient-to-r from-pink-500 to-rose-500 text-white">
              <div className="flex items-center gap-2">
                <UserCircle className="h-5 w-5" />
                <CardTitle className="text-lg">Direct Parties</CardTitle>
              </div>
              <p className="text-pink-100 text-sm">
                No agent - WhatsApp directly to party
              </p>
            </CardHeader>
            <CardContent className="p-0">
              {hasDirectParties ? (
                <ScrollArea className="max-h-[500px]">
                  <InvoiceTable
                    invoices={groupedByAgent["DIRECT"] || []}
                    isDirectParty={true}
                  />
                </ScrollArea>
              ) : (
                <div className="py-8 text-center text-muted-foreground">
                  No direct parties with outstanding invoices
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Summary */}
      {outstandingInvoices.length === 0 && (
        <Card className="mt-6 bg-green-50 border-green-200">
          <CardContent className="py-8 text-center text-green-700">
            <MessageCircle className="h-12 w-12 mx-auto mb-4 opacity-50" />
            <p className="text-lg font-medium">No outstanding invoices found!</p>
            <p className="text-sm text-green-600">All payments are up to date</p>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default WhatsAppReminders;
